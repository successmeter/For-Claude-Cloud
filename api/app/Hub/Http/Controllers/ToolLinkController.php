<?php
// api/app/Hub/Http/Controllers/ToolLinkController.php
namespace App\Hub\Http\Controllers;

use App\Hub\Events\HubEvents;
use App\Hub\Http\Problem;
use App\Hub\Models\OrgToolLink;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ToolLinkController extends Controller
{
    private const TOOLS = ['web'];

    /**
     * PUT /hub/v1/orgs/{org}/tools/{tool} (schema: tool-link). Owner only (with MFA, enforced by
     * `mfa.owner`). Idempotent: linking again updates the ref and clears any revocation.
     */
    public function update(Request $request, string $org, string $tool): JsonResponse
    {
        if ($org !== $request->attributes->get('hub.org_id') || ! in_array($tool, self::TOOLS, true)) {
            return Problem::response(404, 'not_found', 'Not found.');
        }
        if ($request->attributes->get('hub.role') !== 'owner') {
            return Problem::response(403, 'forbidden', 'Only an owner can link a tool.');
        }

        $data = $request->validate(['external_tenant_ref' => ['required', 'string', 'min:1', 'max:200']]);

        $link = OrgToolLink::firstOrNew(['org_id' => $org, 'tool' => $tool]);
        $link->fill([
            'external_tenant_ref' => $data['external_tenant_ref'],
            'linked_by' => $request->user()->id,
            'linked_at' => $link->exists && $link->revoked_at === null ? $link->linked_at : now(),
            'revoked_at' => null,
        ])->save();

        app(AuditLogger::class)->record('tool.linked', 'org_tool_link', $link->id, $org, ['tool' => $tool]);
        app(HubEvents::class)->record(HubEvents::ORG_TOOL_LINKED, $org, $org, [$tool]);

        return response()->json([
            'org_id' => $link->org_id,
            'tool' => $link->tool,
            'external_tenant_ref' => $link->external_tenant_ref,
            'linked_at' => $link->linked_at->toIso8601ZuluString(),
        ]);
    }
}
