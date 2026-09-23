<?php
// api/app/Http/Controllers/Auth/MfaController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    public function enroll(Request $request)
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        if ($user->mfa_enabled) {
            // IMPORTANT findings #7/#8 (final whole-branch review): enroll() used to
            // unconditionally overwrite mfa_secret and reset mfa_enabled to false with
            // no re-authentication check -- an already-authenticated session (or a
            // hijacked session/future XSS) hitting this route a second time silently
            // disabled the user's MFA with zero record of it. Re-enrolling over an
            // already-enabled account now requires proving control of the *current*
            // secret first. First-time enrollment (the `else` implicit below) needs no
            // such check: there's no existing secret to re-authenticate against.
            $data = $request->validate(['code' => ['required', 'string']]);

            if (! $google2fa->verifyKey($user->mfa_secret, $data['code'])) {
                return response()->json(['message' => 'Invalid code'], 422);
            }

            // This is the security-relevant event: an already-MFA-enabled account is
            // about to have its secret replaced and mfa_enabled flipped back to false
            // (i.e. MFA is being reset/disabled until the new secret is confirmed).
            app(AuditLogger::class)->record('mfa_reset', 'user', (string) $user->id);
        }

        $secret = $google2fa->generateSecretKey();

        // forceFill()->save() rather than update(): mfa_secret/mfa_enabled are
        // deliberately excluded from User's #[Fillable] list (see User model), so a
        // plain mass-assigning update() would silently no-op and never persist these
        // columns at all.
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled' => false,
        ])->save();

        return response()->json(['secret' => $secret]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($request->user()->mfa_secret, $data['code']);

        if (! $valid) {
            return response()->json(['message' => 'Invalid code'], 422);
        }

        $request->user()->forceFill(['mfa_enabled' => true])->save();

        // IMPORTANT finding #7: 05-security.md §5.6 requires MFA changes in the audit
        // log; enroll()/confirm() never called AuditLogger at all before this fix.
        app(AuditLogger::class)->record('mfa_enrolled', 'user', (string) $request->user()->id);

        return response()->noContent();
    }

    /**
     * CRITICAL finding #2 (final whole-branch review): completes login for a user
     * whose password was already verified by LoginController::login() but who has
     * MFA enabled. Guest-accessible on purpose -- the caller isn't authenticated yet,
     * so this can't sit behind auth:sanctum. mfa_token is the signed, short-lived,
     * tamper-proof pointer LoginController::login() issued; it identifies the
     * pending user without exposing a raw, guessable user id to the client.
     */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $payload = $this->decodeMfaToken($data['mfa_token']);

        if ($payload === null) {
            return response()->json(['message' => 'Invalid or expired MFA token.'], 422);
        }

        $user = User::find($payload['user_id']);

        if (! $user || ! $user->mfa_enabled) {
            return response()->json(['message' => 'Invalid or expired MFA token.'], 422);
        }

        $google2fa = new Google2FA();

        if (! $google2fa->verifyKey($user->mfa_secret, $data['code'])) {
            return response()->json(['message' => 'Invalid code'], 422);
        }

        // Same session-establishment path (Auth::login + session regenerate + login
        // audit entry + stateful-origin guard) as a normal password-only login
        // success, so a caller of this endpoint gets an identical response shape.
        return LoginController::establishSession($request, $user);
    }

    /**
     * Decrypts and validates an mfa_token from LoginController::login(). Returns
     * null (never throws) for anything tampered, malformed, wrong-purpose, or
     * expired -- callers turn that into a uniform 422 rather than leaking which
     * specific check failed.
     */
    private function decodeMfaToken(string $token): ?array
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (DecryptException) {
            return null;
        }

        if (
            ! is_array($payload)
            || ($payload['purpose'] ?? null) !== 'mfa_login'
            || ! isset($payload['user_id'], $payload['expires_at'])
            || now()->timestamp > (int) $payload['expires_at']
        ) {
            return null;
        }

        return $payload;
    }
}
