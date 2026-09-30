<?php
// api/app/Console/Commands/IngestExpireRuns.php
namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Previews not committed within config('ingest.run_hours') become expired; their staged rows go. */
class IngestExpireRuns extends Command
{
    protected $signature = 'ingest:expire-runs';

    protected $description = 'Expire upload previews that were never committed';

    public function handle(): int
    {
        $expired = 0;
        foreach (DB::table('ingest_orgs')->pluck('org_id') as $orgId) {
            $expired += TenantContext::run($orgId, function () {
                $ids = DB::table('ingestion_runs')->where('status', 'previewed')->where('expires_at', '<', now())->lockForUpdate()->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table('ingestion_runs')->whereIn('id', $ids)->update(['status' => 'expired', 'updated_at' => now()]);
                    DB::table('ingestion_run_rows')->whereIn('run_id', $ids)->delete();
                }

                return $ids->count();
            });
        }
        $this->info("Expired {$expired} upload previews.");

        return self::SUCCESS;
    }
}
