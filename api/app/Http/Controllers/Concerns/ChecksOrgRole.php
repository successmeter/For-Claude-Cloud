<?php
// api/app/Http/Controllers/Concerns/ChecksOrgRole.php
namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The caller's role in the request's org, as the `tenant` middleware resolved it from
 * X-Hub-Org. Bound models are already limited to that org by RLS.
 */
trait ChecksOrgRole
{
    protected function requireRole(Request $request, string ...$roles): void
    {
        abort_unless(in_array($request->attributes->get('hub.role'), $roles, true), 403);
    }

    protected function requireWriter(Request $request): void
    {
        $this->requireRole($request, 'owner', 'manager');
    }

    protected function orgId(Request $request): string
    {
        return $request->attributes->get('hub.org_id');
    }
}
