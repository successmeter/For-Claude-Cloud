<?php
// api/app/Http/Controllers/MeController.php
namespace App\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user and the orgs they belong to (Plan D design §3.2). The Revenue app calls it
 * first, then sends one of these org ids as X-Hub-Org. Ids are public (users.public_id, org uuids).
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user()->refresh();

        $orgs = TenantContext::runAsUser($user->id, fn () => DB::table('memberships')
            ->join('orgs', 'orgs.id', '=', 'memberships.org_id')
            ->where('memberships.user_id', $user->id)
            ->whereNull('orgs.deleted_at')
            ->orderBy('orgs.name')
            ->get(['orgs.id', 'orgs.name', 'memberships.role'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'role' => $row->role])
            ->all());

        return response()->json([
            'user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'mfa_enabled' => (bool) $user->mfa_enabled],
            'orgs' => $orgs,
        ]);
    }
}
