<?php
// api/tests/Feature/Uploads/CommitUploadTest.php

namespace Tests\Feature\Uploads;

use App\Ingest\Snapshots\SnapshotWriter;
use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use App\Services\Encryption\EnvelopeEncryptor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class CommitUploadTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $manager;

    private const MAPPING = ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'gst_inclusive' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('snapshots');
        $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->manager = $this->member($this->org, 'manager');
    }

    private function preview(string $csv): string
    {
        return $this->actingAs($this->manager)->post("/api/venues/{$this->venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)] + self::MAPPING,
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json'])->assertCreated()->json('id');
    }

    private function commit(string $runId, ?User $as = null)
    {
        return $this->spa($as ?? $this->manager, $this->org, 'POST', "/api/uploads/{$runId}/commit");
    }

    private function sales(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('sales_daily')->orderBy('business_date')
            ->pluck('revenue_cents', 'business_date')->map(fn ($c) => (int) $c)->all());
    }

    private function march(int $from, int $to, int $dollars, string $extra = ''): string
    {
        $rows = array_map(fn ($d) => sprintf('2026-03-%02d,%d%s', $d, $dollars, $extra), range($from, $to));

        return 'date,revenue'.($extra === '' ? '' : ',staff')."\n".implode("\n", $rows)."\n";
    }

    public function test_commit_writes_sales_snapshot_metrics_and_insights(): void
    {
        $run = $this->preview($this->march(1, 31, 1000, ',Alice'));

        $this->commit($run)->assertOk()->assertJson(['status' => 'committed']);

        $this->assertCount(31, $this->sales());
        $this->assertSame(31, $this->inTenant($this->org, fn () => DB::table('daily_venue_metrics')->count()));
        $this->assertSame('2026-03-31', $this->inTenant($this->org, fn () => DB::table('insights')->value('as_of')));
        $this->assertSame(0, $this->inTenant($this->org, fn () => DB::table('ingestion_run_rows')->count()), 'staging cleared');

        $snapshot = $this->inTenant($this->org, fn () => DB::table('source_snapshots')->sole());
        $plain = app(EnvelopeEncryptor::class)->decrypt($this->org->id, Storage::disk('snapshots')->get($snapshot->path));
        $this->assertStringStartsWith("business_date,revenue_cents,gst_inclusive,tx_count\n2026-03-01,100000,true,\n", $plain);
        $this->assertStringNotContainsString('Alice', $plain, 'unmapped columns are never kept');
        $this->assertSame(hash('sha256', $plain), $snapshot->sha256);
        $this->assertSame('2026-06-29', CarbonImmutable::parse($snapshot->expires_at)->setTimezone('Australia/Perth')->toDateString());

        $audit = AuditLogEntry::where('action', 'upload.committed')->sole();
        $this->assertEquals(['new' => 31, 'changed' => 0, 'unchanged' => 0, 'first_date' => '2026-03-01', 'last_date' => '2026-03-31'], $audit->meta);
    }

    public function test_changed_days_keep_their_history_and_unchanged_days_are_untouched(): void
    {
        $this->commit($this->preview($this->march(1, 10, 1000)))->assertOk();
        $untouched = $this->inTenant($this->org, fn () => DB::table('sales_daily')->where('business_date', '2026-03-01')->first(['revision', 'revised_at']));

        $second = $this->preview("date,revenue\n2026-03-01,1000\n2026-03-02,1200\n2026-03-11,500\n");
        $this->commit($second)->assertOk();

        $this->assertSame(['2026-03-01' => 100000, '2026-03-02' => 120000, '2026-03-11' => 50000],
            array_intersect_key($this->sales(), array_flip(['2026-03-01', '2026-03-02', '2026-03-11'])));
        $this->assertEquals($untouched, $this->inTenant($this->org, fn () => DB::table('sales_daily')->where('business_date', '2026-03-01')->first(['revision', 'revised_at'])));
        $history = $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->get());
        $this->assertCount(1, $history);
        $this->assertSame(['2026-03-02', 100000, 120000, $second], [$history[0]->business_date, (int) $history[0]->old_revenue_cents, (int) $history[0]->new_revenue_cents, $history[0]->ingestion_run_id]);
        $this->assertSame($second, $this->inTenant($this->org, fn () => DB::table('sales_daily')->where('business_date', '2026-03-02')->value('ingestion_run_id')));
    }

    public function test_metrics_follow_a_correction(): void
    {
        $this->commit($this->preview($this->march(1, 31, 1000)))->assertOk();
        $this->commit($this->preview("date,revenue\n2026-03-25,2000\n"))->assertOk();

        $this->assertSame(800000, (int) $this->metricsOn($this->venue, '2026-03-31')->rolling_7_cents);
    }

    public function test_problems_block_the_commit(): void
    {
        $run = $this->preview("date,revenue\n2026-03-01,1000\nnot a date,5\n");

        $this->commit($run)->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/preview_has_problems');
        $this->assertSame([], $this->sales());
    }

    public function test_a_preview_made_before_another_commit_is_stale(): void
    {
        $first = $this->preview($this->march(1, 5, 1000));
        $second = $this->preview($this->march(1, 5, 2000));

        $this->commit($first)->assertOk();
        $this->commit($second)->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/preview_stale');

        $this->assertSame(100000, $this->sales()['2026-03-01']);
    }

    public function test_a_run_commits_once_and_not_after_expiry(): void
    {
        $run = $this->preview($this->march(1, 5, 1000));
        $this->commit($run)->assertOk();
        $this->commit($run)->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/run_not_committable');

        $late = $this->preview($this->march(6, 7, 1000));
        $this->travel(25)->hours();
        $this->commit($late)->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/run_not_committable');
    }

    public function test_a_failed_snapshot_write_leaves_nothing(): void
    {
        $run = $this->preview($this->march(1, 5, 1000));
        $this->app->instance(SnapshotWriter::class, new class extends SnapshotWriter
        {
            public function __construct() {}

            public function write(string $orgId, string $runId, array $rows): array
            {
                throw new \RuntimeException('disk full');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->commit($run);
            $this->fail('commit succeeded');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame([], $this->sales());
        $this->assertSame('previewed', $this->inTenant($this->org, fn () => DB::table('ingestion_runs')->value('status')));
    }

    public function test_a_failure_after_the_snapshot_is_written_removes_the_file(): void
    {
        $run = $this->preview($this->march(1, 5, 1000));
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $action, string $entityType, string $entityId, ?string $orgId = null, array $meta = []): void
            {
                throw new \RuntimeException('audit down');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->commit($run);
            $this->fail('commit succeeded');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], Storage::disk('snapshots')->allFiles());
        $this->assertSame([], $this->sales());
    }

    public function test_viewers_cannot_commit_and_other_orgs_cannot_see_the_run(): void
    {
        $run = $this->preview($this->march(1, 5, 1000));

        $this->commit($run, $this->member($this->org, 'viewer'))->assertForbidden();

        $other = $this->org('Other');
        $this->spa($this->member($other, 'owner'), $other, 'POST', "/api/uploads/{$run}/commit")->assertNotFound();
    }
}
