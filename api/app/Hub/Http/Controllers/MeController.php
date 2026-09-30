<?php
// api/app/Hub/Http/Controllers/MeController.php
namespace App\Hub\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    /** GET /hub/v1/me (schema: me) */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['sub' => $user->public_id, 'email' => $user->email, 'name' => $user->name]);
    }

    /** GET /hub/v1/me/orgs (schema: org-list) */
    public function orgs(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $orgs = TenantContext::runAsUser($userId, fn () => DB::table('memberships')
            ->join('orgs', 'orgs.id', '=', 'memberships.org_id')
            ->where('memberships.user_id', $userId)
            ->whereNull('orgs.deleted_at')
            ->orderBy('orgs.name')
            ->get(['orgs.id', 'orgs.name', 'memberships.role'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'role' => $row->role])
            ->all());

        return response()->json(['orgs' => $orgs]);
    }
}
