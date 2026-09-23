<?php
// api/tests/Feature/Auth/RateLimitTest.php
namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * Follow-up finding #1 (post-merge review): AppServiceProvider::configureRateLimiting()
 * registers named limiters ('login', 'register', 'mfa-confirm', 'mfa-verify') and
 * routes/api.php applies them via throttle:... middleware, but nothing asserted that
 * exceeding any of them actually produces a 429. These tests prove each limiter fires.
 */
class RateLimitTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml sets CACHE_STORE=array, an in-memory store shared by every test
        // that runs in this PHP process (PHPUnit doesn't spin up a fresh process per
        // test method by default). Without this, a limiter hit recorded by an earlier
        // test/method could bleed into this one -- e.g. two tests both keyed on
        // 127.0.0.1 for the 'register' limiter -- and make these tests flaky/order
        // dependent. Flushing before each test guarantees every limiter here starts
        // from zero hits regardless of what ran before it.
        Cache::flush();
    }

    public function test_login_is_rate_limited_after_five_attempts_from_the_same_email_and_ip(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ], ['Origin' => 'http://localhost']);

            $response->assertStatus(422);
        }

        // 6th attempt within the same minute, same email+IP key: limiter is
        // Limit::perMinute(5), so this must be rejected before the controller even
        // runs the credential check.
        $sixth = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ], ['Origin' => 'http://localhost']);

        $sixth->assertStatus(429);
    }

    public function test_register_is_rate_limited_after_ten_attempts_from_the_same_ip(): void
    {
        // The 'register' limiter is keyed on IP alone and enforced by throttle
        // middleware, which runs before the controller -- so these don't need to be
        // valid/unique registrations to prove the limiter fires. Faked anyway so any
        // request that *does* reach the controller (attempts 1-10) doesn't make a real
        // outbound call to pwnedpasswords.com.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/register', [
                'org_name' => "Org {$i}",
                'name' => 'Jane Owner',
                'email' => "rate-limit-register-{$i}@example.com",
                'password' => 'Correct-Horse-Battery-Staple9',
                'password_confirmation' => 'Correct-Horse-Battery-Staple9',
            ], ['Origin' => 'http://localhost']);
        }

        // 11th attempt within the same hour, same IP: limiter is Limit::perHour(10).
        $eleventh = $this->postJson('/api/register', [
            'org_name' => 'Org 11',
            'name' => 'Jane Owner',
            'email' => 'rate-limit-register-11@example.com',
            'password' => 'Correct-Horse-Battery-Staple9',
            'password_confirmation' => 'Correct-Horse-Battery-Staple9',
        ], ['Origin' => 'http://localhost']);

        $eleventh->assertStatus(429);
    }

    public function test_mfa_confirm_is_rate_limited_after_five_attempts_for_the_same_user(): void
    {
        $google2fa = new Google2FA();

        // mfa_secret is set directly via the factory (the same pattern MfaTest.php's
        // login-requires-second-step test already uses) rather than via a real
        // /api/mfa/enroll call -- enroll() now shares the 'mfa-confirm' limiter bucket
        // (see follow-up finding #3), so calling it first would consume one of this
        // test's five attempts and throw off the boundary being asserted here.
        $user = User::factory()->create([
            'mfa_secret' => $google2fa->generateSecretKey(),
            'mfa_enabled' => false,
        ]);
        $this->actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/mfa/confirm', ['code' => '000000']);
            $response->assertStatus(422);
        }

        // 6th attempt within the same minute, same authenticated-user key: limiter is
        // Limit::perMinute(5)->by($request->user()?->id).
        $sixth = $this->postJson('/api/mfa/confirm', ['code' => '000000']);
        $sixth->assertStatus(429);
    }

    public function test_mfa_verify_is_rate_limited_after_five_attempts_for_the_same_token(): void
    {
        // The 'mfa-verify' limiter is keyed on IP+mfa_token from raw request input --
        // it never decrypts the token -- so an arbitrary string exercises the limiter
        // exactly the same as a real (or expired/tampered) one would.
        $token = 'not-a-real-mfa-token';

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/mfa/verify', [
                'mfa_token' => $token,
                'code' => '000000',
            ]);
            $response->assertStatus(422);
        }

        // 6th attempt within the same minute, same IP+token key: limiter is
        // Limit::perMinute(5).
        $sixth = $this->postJson('/api/mfa/verify', [
            'mfa_token' => $token,
            'code' => '000000',
        ]);
        $sixth->assertStatus(429);
    }

    /**
     * Follow-up finding #3: /api/mfa/enroll's re-enrollment TOTP check
     * (MfaController::enroll()'s `if ($user->mfa_enabled)` branch) previously had no
     * throttle at all, reopening the exact brute-force class the 'mfa-confirm' /
     * 'mfa-verify' limiters closed elsewhere -- on the very route that specific fix
     * created. Proves the newly-added throttle:mfa-confirm middleware on that route
     * actually fires.
     */
    public function test_mfa_enroll_reenrollment_is_rate_limited_after_five_attempts(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);
        $this->actingAs($user);

        $validCode = $google2fa->getCurrentOtp($secret);
        $wrongCode = $validCode === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/mfa/enroll', ['code' => $wrongCode]);
            $response->assertStatus(422);
        }

        // 6th attempt within the same minute, same authenticated-user key (shared
        // 'mfa-confirm' bucket with /api/mfa/confirm by design -- same threat model).
        $sixth = $this->postJson('/api/mfa/enroll', ['code' => $wrongCode]);
        $sixth->assertStatus(429);
    }
}
