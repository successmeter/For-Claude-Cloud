<?php
// api/app/Http/Controllers/Auth/LoginController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Minutes an mfa_token issued by login() remains valid for use against
     * MfaController::verify(). Kept short: this token exists only to bridge the two
     * requests of a single MFA login attempt.
     */
    public const MFA_TOKEN_TTL_MINUTES = 5;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->mfa_enabled) {
            // CRITICAL finding #2 (final whole-branch review): previously this
            // returned {"mfa_required": true} and nothing else in routes/api.php let
            // the user supply a TOTP code to actually finish logging in -- a live,
            // reachable, self-inflicted lockout for every MFA-enrolled user. The
            // token below is a signed, tamper-proof, short-lived pointer to "this
            // specific user is mid-login", consumed by MfaController::verify(). It is
            // NOT a session and does not authenticate anything by itself -- Crypt
            // uses APP_KEY-based authenticated encryption (see
            // Illuminate\Encryption\Encrypter), so a client can't forge or edit it,
            // and the embedded expiry bounds how long a captured token is useful.
            // Deliberately returns without touching $request->session() at all or
            // calling Auth::login() -- the user is NOT authenticated yet, so this
            // branch must behave identically whether or not StartSession ran for this
            // request (i.e. regardless of stateful-origin gating in
            // bootstrap/app.php), unlike establishSession() below which needs one.
            $mfaToken = Crypt::encrypt([
                'purpose' => 'mfa_login',
                'user_id' => $user->id,
                'expires_at' => now()->addMinutes(self::MFA_TOKEN_TTL_MINUTES)->timestamp,
            ]);

            return response()->json([
                'mfa_required' => true,
                'mfa_token' => $mfaToken,
            ]);
        }

        return $this->establishSession($request, $user);
    }

    public function logout(Request $request)
    {
        // Captured before Auth::logout() runs: Auth::id() becomes null immediately
        // after logout, so AuditLogger::record()'s actor_id lookup must happen first.
        app(AuditLogger::class)->record('logout', 'user', (string) Auth::id());

        // The session guard, by name: inside `auth:sanctum` the default guard is Sanctum's request
        // guard, which has no logout(), so Auth::logout() failed and the session survived.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Finish authenticating $user on $request: start the real session, rotate the
     * session id, and audit-log the login. Shared by the direct (no-MFA) login
     * success path and MfaController::verify()'s post-TOTP success path, so both
     * behave identically once a user is determined to be genuinely authenticated.
     */
    public static function establishSession(Request $request, User $user)
    {
        if (! $request->hasSession()) {
            // Minor finding #13 (final whole-branch review): this branch used to
            // unconditionally call $request->session()->regenerate(), which throws
            // ("Session store not set on request.") when StartSession never ran for
            // this request -- i.e. any request from a non-stateful origin, per
            // bootstrap/app.php's EnsureFrontendRequestsAreStateful gating. That
            // surfaced as an unhandled 500, which StatefulOriginGatingTest previously
            // asserted directly, codifying the bug as expected behavior. A clean 4xx
            // here matches the hasSession()-aware pattern RegisterController already
            // uses for the same non-stateful-origin case.
            return response()->json([
                'message' => 'This endpoint requires a stateful (first-party) request origin.',
            ], 400);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Recorded after Auth::login()+session regenerate, so that
        // AuditLogger::record()'s Auth::id() lookup resolves to the just-logged-in
        // user instead of null.
        app(AuditLogger::class)->record('login', 'user', (string) $user->id);

        return response()->json(['id' => $user->refresh()->public_id]);
    }
}
