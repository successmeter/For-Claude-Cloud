<?php
// api/app/Console/Commands/PosSync.php
namespace App\Console\Commands;

use App\Pos\Square\SyncSquareLocation;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Nightly: queue a sync for every linked location of every healthy connection, spread over an hour. */
class PosSync extends Command
{
    protected $signature = 'pos:sync {--spread=3600 : seconds to spread the jobs over}';

    protected $description = 'Queue the nightly POS sales sync';

    public function handle(): int
    {
        $queued = 0;
        foreach (DB::table('pos_orgs')->pluck('org_id') as $orgId) {
            $locations = TenantContext::run($orgId, fn () => DB::table('pos_location_links as l')
                ->join('pos_connections as c', 'c.id', '=', 'l.connection_id')
                ->where('c.status', 'connected')->whereNull('c.paused_at')->pluck('l.location_id'));
            foreach ($locations as $location) {
                SyncSquareLocation::dispatch($orgId, $location)->delay(now()->addSeconds(random_int(0, max(0, (int) $this->option('spread')))));
                $queued++;
            }
        }
        $this->info("Queued {$queued} location syncs.");

        return self::SUCCESS;
    }
}
