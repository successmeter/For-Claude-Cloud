<?php
// api/app/Http/Controllers/Pos/SquareSyncController.php
namespace App\Http\Controllers\Pos;

use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Pos\Square\SyncSquareLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Sync now" (Plan E design §3 Scheduler): owners and managers, once per 10 minutes per org. It also
 * resumes a connection the circuit breaker paused.
 */
class SquareSyncController extends Controller
{
    use ChecksOrgRole;

    public function __invoke(Request $request): JsonResponse
    {
        $this->requireWriter($request);
        $orgId = $this->orgId($request);
        $connection = DB::table('pos_connections')->where('provider', 'square')->first() ?? throw HubProblem::notFound();
        if ($connection->status === 'needs_reauth') {
            throw new HubProblem(409, 'square_needs_reauth', 'Square needs the owner to reconnect.');
        }
        if (! RateLimiter::attempt("pos-sync:{$orgId}", 1, fn () => true, 600)) {
            throw new HubProblem(429, 'sync_too_soon', 'A sync was started in the last 10 minutes.');
        }

        DB::table('pos_connections')->where('id', $connection->id)->update(['paused_at' => null, 'consecutive_failures' => 0, 'updated_at' => now()]);
        $locations = DB::table('pos_location_links')->where('connection_id', $connection->id)->pluck('location_id');
        // After this request's transaction commits, so the job sees the resumed connection.
        foreach ($locations as $location) {
            SyncSquareLocation::dispatch($orgId, $location)->afterCommit();
        }

        return response()->json(['queued' => $locations->count()], 202);
    }
}
