<?php
// api/app/Http/Middleware/ResolveTenant.php
namespace App\Http\Middleware;

use App\Hub\Http\HubCaller;
use App\Hub\Http\Problem;
use App\Hub\Models\OrgToolLink;
use App\Models\Membership;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolves the org a request acts for from the X-Hub-Org header, checks the caller belongs to it,
 * and runs the rest of the request (route-model binding included; see the priority list in
 * bootstrap/app.php) inside TenantContext::run() for that org.
 *
 * Replaces Plan A's SetTenantContext, whose X-Org-Id header was unauthenticated input honoured
 * in local/testing only. Here the header is only a selector: membership decides.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $orgId = $request->header('X-Hub-Org');
        if (! $orgId) {
            return Problem::response(400, 'org_required', 'X-Hub-Org header is required.');
        }
        // Not-a-UUID is answered exactly like "not your org" so the response never tells a
        // caller anything about which orgs exist.
        if (! Str::isUuid($orgId)) {
            return Problem::response(404, 'org_not_found', 'Organisation not found.');
        }

        return TenantContext::run($orgId, function () use ($request, $next, $orgId) {
            $role = $this->roleFor($request);
            if ($role === null) {
                return Problem::response(404, 'org_not_found', 'Organisation not found.');
            }
            $request->attributes->set('hub.org_id', $orgId);
            $request->attributes->set('hub.role', $role);

            return $next($request);
        });
    }

    /**
     * Runs under the org's tenant context, so RLS already limits memberships and tool links to
     * that org: any row means "member of" / "linked to" this org.
     *
     * A user acts with their membership role. A tool acting on its own (client-credentials token,
     * no user) gets the role `tool`, and only for orgs that linked it (design §4.2).
     */
    protected function roleFor(Request $request): ?string
    {
        $user = $request->user();
        if ($user) {
            return Membership::where('user_id', $user->id)->value('role');
        }

        $caller = HubCaller::of($request);
        if ($caller?->tool !== null
            && OrgToolLink::where('tool', $caller->tool)->whereNull('revoked_at')->exists()) {
            return 'tool';
        }

        return null;
    }
}
