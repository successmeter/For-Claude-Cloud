<?php
// api/app/Console/Commands/IngestPruneSnapshots.php
namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes upload snapshots past their retention (config('ingest.snapshot_days')): the file, then
 * the row. Also deletes files under an org's folder that are past retention with no row, which a
 * commit that failed after writing its file could leave behind.
 */
class IngestPruneSnapshots extends Command
{
    protected $signature = 'ingest:prune-snapshots';

    protected $description = 'Delete upload snapshots past their retention';

    public function handle(): int
    {
        $disk = Storage::disk('snapshots');
        $cutoff = now()->subDays(config('ingest.snapshot_days'))->getTimestamp();
        $pruned = 0;

        foreach (DB::table('ingest_orgs')->pluck('org_id') as $orgId) {
            $pruned += TenantContext::run($orgId, function () use ($disk, $cutoff, $orgId) {
                $count = 0;
                foreach (DB::table('source_snapshots')->where('expires_at', '<', now())->get(['id', 'path']) as $snapshot) {
                    $disk->delete($snapshot->path);
                    DB::table('source_snapshots')->where('id', $snapshot->id)->delete();
                    $count++;
                }

                $known = DB::table('source_snapshots')->pluck('path')->flip();
                foreach ($disk->files($orgId) as $path) {
                    if (! $known->has($path) && $disk->lastModified($path) < $cutoff) {
                        $disk->delete($path);
                        $count++;
                    }
                }

                return $count;
            });
        }
        $this->info("Pruned {$pruned} snapshot files.");

        return self::SUCCESS;
    }
}
