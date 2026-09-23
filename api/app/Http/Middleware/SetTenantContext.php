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
        // Temporary: prefers a request attribute (settable by earlier, trusted,
        // authenticated middleware — wired in a later plan once org-scoped API endpoints
        // and sessions exist). No task in Plan A wires that attribute yet, so in the
        // meantime we also accept the X-Org-Id header, but ONLY in local/testing
        // environments. The header is unauthenticated input — anyone can set it to any
        // UUID — so honoring it in staging/production would grant RLS-blessed access to
        // any org the moment any route actually uses the `api` middleware group (it
        // doesn't yet, but this guard means that can't silently start working once it
        // does). Real session-derived tenant wiring is deferred to a later plan; for now
        // this only needs to satisfy the RLS tests, which either call
        // TenantContext::set() directly or run in the `testing` environment.
        $orgId = $request->attributes->get('current_org_id');

        if (! $orgId && app()->environment('local', 'testing')) {
            $orgId = $request->header('X-Org-Id');
        }

        if ($orgId) {
            TenantContext::set($orgId);
        } else {
            TenantContext::clear();
        }

        return $next($request);
    }
}
