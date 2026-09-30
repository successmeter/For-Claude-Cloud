<?php
// api/app/Hub/Http/Middleware/RequireUserCaller.php
namespace App\Hub\Http\Middleware;

use App\Hub\Http\HubCaller;
use App\Hub\Http\Problem;
use Closure;
use Illuminate\Http\Request;

/**
 * `hub.user` -- the route acts for a person, so a tool's own (client-credentials) token is refused.
 */
class RequireUserCaller
{
    public function handle(Request $request, Closure $next)
    {
        if (HubCaller::of($request)?->user === null) {
            return Problem::response(403, 'user_required', 'This endpoint needs a user token, not a tool token.');
        }

        return $next($request);
    }
}
