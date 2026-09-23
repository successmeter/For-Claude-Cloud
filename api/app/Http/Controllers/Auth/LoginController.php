<?php
// api/app/Http/Controllers/Auth/LoginController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
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
            // Full session-based MFA challenge state (short-lived token) is finalized
            // when the login UI is built in Plan D; for now this proves the branch
            // exists. Deliberately returns without touching $request->session() at
            // all or calling Auth::login() -- the user is NOT authenticated yet, so
            // this branch must behave identically whether or not StartSession ran for
            // this request (i.e. regardless of stateful-origin gating in
            // bootstrap/app.php), unlike the success branch below which needs a
            // session.
            return response()->json(['mfa_required' => true]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Recorded after Auth::login()+session regenerate (not before, unlike the
        // mfa_required branch above which never authenticates at all), so that
        // AuditLogger::record()'s Auth::id() lookup resolves to the just-logged-in
        // user instead of null.
        app(\App\Services\Audit\AuditLogger::class)->record('login', 'user', (string) $user->id);

        return response()->json(['id' => $user->id]);
    }

    public function logout(Request $request)
    {
        // Captured before Auth::logout() runs: Auth::id() becomes null immediately
        // after logout, so AuditLogger::record()'s actor_id lookup must happen first.
        app(\App\Services\Audit\AuditLogger::class)->record('logout', 'user', (string) Auth::id());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
