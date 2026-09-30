<?php
// api/app/Http/Middleware/EnsureOwnerHasMfa.php
namespace App\Http\Middleware;

use App\Hub\Http\Problem;
use Closure;
use Illuminate\Http\Request;

/**
 * Plan A Open Item 3: owners must have MFA (05-security.md §5.3). Applied to owner-only routes,
 * after `tenant` (which sets hub.role for the org the request acts for). Only binds owners, so a
 * route that also admits managers still admits a manager without MFA; role checks stay with the
 * route itself.
 */
class EnsureOwnerHasMfa
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->attributes->get('hub.role') === 'owner' && ! $request->user()?->mfa_enabled) {
            return Problem::response(403, 'mfa_required', 'Owners must enable MFA for this action.');
        }

        return $next($request);
    }
}
