<?php
// api/tests/Feature/Auth/MfaTest.php
namespace Tests\Feature\Auth;

use App\Models\User;
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
}
