<?php
// api/app/Http/Controllers/Auth/PasswordResetController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Hub\Http\Problem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Forgotten passwords. The link goes to the Revenue app (APP_FRONTEND_URL/reset-password), lasts
 * 60 minutes and works once. Asking for one answers the same whether or not the address has an
 * account. A reset ends every session and Hub sign-in token the person had; MFA is unchanged.
 */
class PasswordResetController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function forgot(Request $request): Response
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();
        if ($user) {
            // The broker's own throttle (one email a minute per account) applies; its answer is not
            // passed on, so the response never says whether the account exists.
            Password::broker()->sendResetLink(['email' => $user->email]);
            $this->audit->record('password.reset_requested', 'user', (string) $user->id);
        }

        return response()->noContent();
    }

    public function reset(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            // Plan A's rules, as at sign-up.
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();
        $status = $user
            ? Password::broker()->reset(
                ['email' => $user->email, 'token' => $data['token'], 'password' => $data['password']],
                function (User $user, string $password) {
                    DB::transaction(function () use ($user, $password) {
                        $user->forceFill(['password' => $password])->save();
                        // Signed out everywhere: Revenue app sessions and the Web tool's Hub tokens.
                        DB::table('sessions')->where('user_id', $user->id)->delete();
                        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->getAuthIdentifier())->pluck('id');
                        DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
                        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
                    });
                })
            : Password::INVALID_USER;

        if ($status !== Password::PASSWORD_RESET) {
            return Problem::response(422, 'reset_link_invalid', 'This reset link is not valid. It may have expired or already been used.');
        }

        $this->audit->record('password.reset', 'user', (string) $user->id);

        return response()->noContent();
    }
}
