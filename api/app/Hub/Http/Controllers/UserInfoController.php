<?php
// api/app/Hub/Http/Controllers/UserInfoController.php
namespace App\Hub\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OIDC UserInfo. Replaces the package's controller, which reports `sub` as the bigint users.id
 * (Plan B spike finding 1). Claims follow the access token's scopes, like the id_token.
 */
class UserInfoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->token();

        $claims = ['sub' => $user->public_id];
        if ($token->can('profile')) {
            $claims['name'] = $user->name;
        }
        if ($token->can('email')) {
            $claims['email'] = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }

        return response()->json($claims);
    }
}
