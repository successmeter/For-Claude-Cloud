<?php
// api/app/Pos/Square/SyncSquareLocation.php
namespace App\Pos\Square;

use App\Jobs\Contracts\TenantScopedJob;
use App\Jobs\Middleware\RunsWithTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Syncs one linked Square location (Plan E design §3). The sync runs in a savepoint: when it fails,
 * its writes are undone but the failure is recorded on the connection (a refused token ->
 * needs_reauth; otherwise a failure count, and after 5 in a row the connection pauses until an
 * owner or manager syncs by hand or reconnects). The job itself does not retry: the next night does.
 */
class SyncSquareLocation implements ShouldQueue, TenantScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BREAKER = 5;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(private string $orgId, private string $locationId) {}

    public function orgId(): string
    {
        return $this->orgId;
    }

    public function middleware(): array
    {
        return [new RunsWithTenantContext];
    }

    public function handle(SquareSync $sync): void
    {
        $connection = DB::table('pos_connections')->where('provider', 'square')->lockForUpdate()->first();
        if ($connection === null || $connection->status !== 'connected' || $connection->paused_at !== null) {
            return;
        }
        $link = DB::table('pos_location_links')->where('connection_id', $connection->id)->where('location_id', $this->locationId)->first();
        if ($link === null) {
            return;
        }

        try {
            DB::transaction(fn () => $sync->run(SquareClient::forOrg(), $link));
        } catch (SquareAuthFailed) {
            DB::table('pos_connections')->where('id', $connection->id)
                ->update(['status' => 'needs_reauth', 'last_error' => 'Square needs the owner to reconnect.', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $failures = $connection->consecutive_failures + 1;
            DB::table('pos_connections')->where('id', $connection->id)->update([
                'consecutive_failures' => $failures,
                'paused_at' => $failures >= self::BREAKER ? now() : null,
                'last_error' => match (true) {
                    $e instanceof SquareUnavailable => 'Square was not answering.',
                    $e instanceof SquareRequestFailed => 'Square turned down a request.',
                    default => 'The sync failed.',
                },
                'updated_at' => now(),
            ]);
            if (! $e instanceof SquareUnavailable && ! $e instanceof SquareRequestFailed) {
                report($e);
            }
        }
    }
}
