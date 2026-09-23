<?php
// api/tests/Feature/Auth/MfaTest.php
namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_a_user_can_enroll_and_confirm_mfa(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $enroll = $this->postJson('/api/mfa/enroll');
        $enroll->assertOk();
        $secret = $enroll->json('secret');

        $google2fa = new Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        // MfaController::confirm() returns response()->noContent() (204) on success,
        // per the brief's Step 4 controller code -- not 200, so this asserts the
        // actual documented contract rather than the brief's Step 2 test snippet,
        // which called assertOk() (200) and would fail against that controller.
        $confirm = $this->postJson('/api/mfa/confirm', ['code' => $validCode]);
        $confirm->assertNoContent();

        $this->assertTrue($user->fresh()->mfa_enabled);
    }

    public function test_login_requires_a_second_step_when_mfa_is_enabled(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'password' => bcrypt('correct-horse-battery-staple'),
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        // Unlike LoginTest's success-path assertions, this branch of
        // LoginController::login() returns before touching $request->session() or
        // calling Auth::login() at all, so -- per the task brief -- it's worth
        // confirming empirically whether it still requires the Origin header that
        // the stateful-origin gating in bootstrap/app.php demands for the session
        // branch. No Origin/Referer header is sent here on purpose.
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $response->assertJson(['mfa_required' => true]);
        $this->assertGuest(); // not fully authenticated until the TOTP step completes
    }

    /**
     * Full round-trip proof that MfaController::verify() closes the permanent-lockout
     * bug: an MFA-enabled user must be able to actually finish logging in via the
     * mfa_token issued by LoginController::login(), not just receive a 200 with
     * mfa_required=true and nowhere to go from there.
     */
    public function test_mfa_verify_completes_login_with_a_valid_token_and_code(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'password' => bcrypt('correct-horse-battery-staple'),
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        // Origin header included on the /api/login call too, even though the
        // mfa_required branch doesn't itself touch the session (confirmed by the
        // sibling test above, which omits it) -- kept here so the login call matches
        // a real browser request as closely as possible.
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ], ['Origin' => 'http://localhost']);

        $login->assertOk();
        $login->assertJson(['mfa_required' => true]);
        $mfaToken = $login->json('mfa_token');
        $this->assertNotEmpty($mfaToken);

        $code = $google2fa->getCurrentOtp($secret);

        // Empirically required: MfaController::verify() finishes via
        // LoginController::establishSession(), which -- unlike the mfa_required
        // branch above -- calls $request->session()->regenerate() and therefore needs
        // StartSession to have run, which only happens for stateful-origin requests
        // (see bootstrap/app.php's EnsureFrontendRequestsAreStateful gating, and
        // LoginTest/RegistrationTest's identical use of this header). Verified by
        // running this test without the header first: it 400'd with "This endpoint
        // requires a stateful (first-party) request origin." instead of asserting ok.
        $verify = $this->postJson('/api/mfa/verify', [
            'mfa_token' => $mfaToken,
            'code' => $code,
        ], ['Origin' => 'http://localhost']);

        $verify->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_mfa_verify_rejects_an_incorrect_code(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'password' => bcrypt('correct-horse-battery-staple'),
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ], ['Origin' => 'http://localhost']);

        $mfaToken = $login->json('mfa_token');

        // Guaranteed distinct from the real current OTP rather than relying on a
        // 1-in-a-million coincidence with a fixed '000000'.
        $validCode = $google2fa->getCurrentOtp($secret);
        $wrongCode = $validCode === '000000' ? '111111' : '000000';

        $verify = $this->postJson('/api/mfa/verify', [
            'mfa_token' => $mfaToken,
            'code' => $wrongCode,
        ], ['Origin' => 'http://localhost']);

        $verify->assertStatus(422);
        $this->assertGuest();
    }

    public function test_mfa_verify_rejects_an_expired_token(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        // Constructed directly via Crypt::encrypt() with a past expires_at, matching
        // the exact shape MfaController::decodeMfaToken() expects (see that method:
        // purpose/user_id/expires_at keys), rather than time-travelling past the
        // 5-minute TTL, which would also require freezing every other timestamp this
        // test touches.
        $expiredToken = Crypt::encrypt([
            'purpose' => 'mfa_login',
            'user_id' => $user->id,
            'expires_at' => now()->subMinute()->timestamp,
        ]);

        $verify = $this->postJson('/api/mfa/verify', [
            'mfa_token' => $expiredToken,
            'code' => $google2fa->getCurrentOtp($secret),
        ], ['Origin' => 'http://localhost']);

        $verify->assertStatus(422);
        $this->assertGuest();
    }

    public function test_mfa_verify_rejects_a_tampered_token(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        $token = Crypt::encrypt([
            'purpose' => 'mfa_login',
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);

        // Corrupting the ciphertext must be caught as a DecryptException inside
        // decodeMfaToken() and turned into a uniform 422 -- not leak out as an
        // unhandled 500.
        $tamperedToken = $token.'tampered';

        $verify = $this->postJson('/api/mfa/verify', [
            'mfa_token' => $tamperedToken,
            'code' => $google2fa->getCurrentOtp($secret),
        ], ['Origin' => 'http://localhost']);

        $verify->assertStatus(422);
        $this->assertGuest();
    }
}
