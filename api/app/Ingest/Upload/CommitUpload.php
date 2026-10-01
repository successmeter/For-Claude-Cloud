<?php
// api/app/Ingest/Upload/CommitUpload.php
namespace App\Ingest\Upload;

use App\Bench\RecomputeVenue;
use App\Covers\CoversService;
use App\Hub\Exceptions\HubProblem;
use App\Ingest\Models\IngestionRun;
use App\Ingest\Snapshots\SnapshotWriter;
use App\Services\Audit\AuditLogger;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a previewed upload (Plan C design §4.4) inside the request's tenant transaction: the
 * previewed rows exactly, with history for changed days, then metrics and insights, then the
 * encrypted snapshot. Any failure rolls all of it back; a snapshot file already written is deleted.
 */
class CommitUpload
{
    public function __construct(private RecomputeVenue $recompute, private SnapshotWriter $snapshots, private AuditLogger $audit, private CoversService $covers) {}

    public function commit(IngestionRun $run): IngestionRun
    {
        // One commit per venue at a time; then re-read the run under lock.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$run->venue_id]);
        $run = IngestionRun::whereKey($run->id)->lockForUpdate()->firstOrFail();

        if ($run->status !== 'previewed' || $run->expires_at->isPast()) {
            throw new HubProblem(409, 'run_not_committable', 'This upload was already committed, discarded or has expired. Upload the file again.');
        }
        if ($run->summary['problems'] > 0) {
            throw new HubProblem(409, 'preview_has_problems', 'Fix the rows listed in the preview and upload the file again.');
        }
        if ((int) DB::table('sales_daily')->where('venue_id', $run->venue_id)->max('revision') !== $run->basis_revision) {
            throw new HubProblem(409, 'preview_stale', "This venue's sales changed since the preview. Upload the file again.");
        }

        $bind = ['run' => $run->id, 'venue' => $run->venue_id];
        DB::statement(<<<'SQL'
            INSERT INTO sales_daily_revisions (org_id, venue_id, business_date, old_revenue_cents, old_gst_inclusive, old_tx_count,
                old_food_cents, old_drinks_cents, old_other_cents,
                new_revenue_cents, new_gst_inclusive, new_tx_count, new_food_cents, new_drinks_cents, new_other_cents, ingestion_run_id, revised_at)
            SELECT s.org_id, s.venue_id, s.business_date, s.revenue_cents, s.gst_inclusive, s.tx_count,
                   s.food_cents, s.drinks_cents, s.other_cents,
                   r.revenue_cents, r.gst_inclusive, r.tx_count, r.food_cents, r.drinks_cents, r.other_cents, r.run_id, now()
            FROM ingestion_run_rows r
            JOIN sales_daily s ON s.venue_id = :venue AND s.business_date = r.business_date
            WHERE r.run_id = :run AND r.change = 'changed'
        SQL, $bind);
        DB::statement(<<<'SQL'
            UPDATE sales_daily s SET revenue_cents = r.revenue_cents, gst_inclusive = r.gst_inclusive, tx_count = r.tx_count,
                food_cents = r.food_cents, drinks_cents = r.drinks_cents, other_cents = r.other_cents,
                source = 'upload', ingestion_run_id = r.run_id, revision = nextval('sales_daily_revision_seq'), revised_at = now()
            FROM ingestion_run_rows r
            WHERE r.run_id = :run AND r.change = 'changed' AND s.venue_id = :venue AND s.business_date = r.business_date
        SQL, $bind);
        DB::statement(<<<'SQL'
            INSERT INTO sales_daily (org_id, venue_id, business_date, revenue_cents, gst_inclusive, tx_count,
                food_cents, drinks_cents, other_cents, source, ingestion_run_id, created_at, revised_at)
            SELECT org_id, :venue, business_date, revenue_cents, gst_inclusive, tx_count,
                food_cents, drinks_cents, other_cents, 'upload', run_id, now(), now()
            FROM ingestion_run_rows WHERE run_id = :run AND change = 'new'
        SQL, $bind);

        // Covers from the file are the venue's own figures (Plan E §2.4).
        $covers = DB::table('ingestion_run_rows')->where('run_id', $run->id)->where('covers_changed', true)
            ->orderBy('business_date')->pluck('covers', 'business_date')->map(fn ($c) => (int) $c)->all();
        $this->covers->save(Venue::findOrFail($run->venue_id), $covers, 'upload', Auth::id(), recompute: false);

        $earliest = DB::table('ingestion_run_rows')->where('run_id', $run->id)
            ->where(fn ($q) => $q->whereIn('change', ['new', 'changed'])->orWhere('covers_changed', true))->min('business_date');
        if ($earliest !== null) {
            $this->recompute->run($run->venue_id, CarbonImmutable::parse($earliest));
        }

        $rows = DB::table('ingestion_run_rows')->where('run_id', $run->id)->orderBy('business_date')
            ->get(['business_date', 'revenue_cents', 'gst_inclusive', 'tx_count', 'food_cents', 'drinks_cents', 'covers'])->all();
        $file = $this->snapshots->write($run->org_id, $run->id, $rows);

        try {
            DB::table('source_snapshots')->insert([
                'id' => (string) Str::uuid(), 'org_id' => $run->org_id, 'run_id' => $run->id, 'path' => $file['path'],
                'sha256' => $file['sha256'], 'bytes' => $file['bytes'], 'created_at' => now(),
                'expires_at' => now()->addDays(config('ingest.snapshot_days')),
            ]);
            $run->update(['status' => 'committed', 'committed_at' => now()]);
            DB::table('ingestion_run_rows')->where('run_id', $run->id)->delete();
            $this->audit->record('upload.committed', 'ingestion_run', $run->id, $run->org_id, [
                'new' => $run->summary['new'], 'changed' => $run->summary['changed'], 'unchanged' => $run->summary['unchanged'],
                'covers' => $run->summary['covers'] ?? 0,
                'first_date' => $run->summary['first_date'], 'last_date' => $run->summary['last_date'],
            ]);
        } catch (\Throwable $e) {
            $this->snapshots->delete($file['path']);

            throw $e;
        }

        return $run->refresh();
    }
}
