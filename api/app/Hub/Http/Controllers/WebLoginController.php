<?php
// api/app/Hub/Http/Controllers/WebLoginController.php
namespace App\Hub\Http\Controllers;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

/**
 * Server-rendered login for the OIDC authorize endpoint (Plan B Task 8). /oauth/authorize sends
 * unauthenticated browsers to the route named `login`; Plan A's login is a JSON API for the SPA.
 * Same credential and TOTP checks as LoginController/MfaController, same error message.
 *
 * Between the password and TOTP steps the pending user lives in the (server-side) session, not in
 * a token handed to the browser, with the same lifetime as the JSON flow's mfa_token.
 */
class WebLoginController extends Controller
{
    private const PENDING_USER = 'login.pending_user_id';

    private const PENDING_UNTIL = 'login.pending_until';

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
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
            // New session id before holding any login state in it (session fixation).
            $request->session()->regenerate();
            $request->session()->put(self::PENDING_USER, $user->id);
            $request->session()->put(self::PENDING_UNTIL, now()->addMinutes(LoginController::MFA_TOKEN_TTL_MINUTES)->timestamp);

            return redirect()->route('login.mfa');
        }

        return $this->complete($request, $user);
    }

    public function showMfa(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.mfa');
    }

    public function verifyMfa(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['required', 'string']]);

        if (! (new Google2FA)->verifyKey($user->mfa_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['Invalid code.']]);
        }

        return $this->complete($request, $user);
    }

    public function logout(Request $request): RedirectResponse
    {
        app(AuditLogger::class)->record('logout', 'user', (string) Auth::id(), null, ['channel' => 'oidc']);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_USER);
        $until = (int) $request->session()->get(self::PENDING_UNTIL, 0);

        if (! $id || now()->timestamp > $until) {
            $request->session()->forget([self::PENDING_USER, self::PENDING_UNTIL]);

            return null;
        }

        $user = User::find($id);

        return ($user && $user->mfa_enabled) ? $user : null;
    }

    private function complete(Request $request, User $user): RedirectResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget([self::PENDING_USER, self::PENDING_UNTIL]);

        app(AuditLogger::class)->record('login', 'user', (string) $user->id, null, ['channel' => 'oidc']);

        return redirect()->intended('/');
    }
}
