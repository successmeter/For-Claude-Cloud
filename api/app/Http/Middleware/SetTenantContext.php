<?php
// api/app/Http/Middleware/SetTenantContext.php
namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class SetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        // Temporary: reads a request attribute (settable by earlier middleware) or the
        // X-Org-Id header. No task in Plan A wires this from an authenticated user's
        // session/org membership — that requires org-scoped API endpoints, which don't
        // exist until a later plan (Plan B or C). Real session-derived tenant wiring is
        // deferred to that later plan; for now this only needs to satisfy the RLS test,
        // which calls TenantContext::set() directly.
        $orgId = $request->attributes->get('current_org_id')
            ?? $request->header('X-Org-Id');

        if ($orgId) {
            TenantContext::set($orgId);
        } else {
            TenantContext::clear();
        }

        return $next($request);
    }
}
