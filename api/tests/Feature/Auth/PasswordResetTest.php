<?php
// api/tests/Feature/Auth/PasswordResetTest.php

namespace Tests\Feature\Auth;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    private const NEW_PASSWORD = 'Brand-New-Password-7';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // the breached-password check answers "not found"
        config(['app.frontend_url' => 'https://app.example.test', 'openid.forceHttps' => false]);
    }

    private function reset(array $data)
    {
        return $this->postJson('/api/password/reset', $data + ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
    }

    public function test_a_known_address_gets_a_link_to_the_app(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'sam@example.com']);

        $this->postJson('/api/password/forgot', ['email' => 'SAM@example.com'])->assertNoContent();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://app.example.test/reset-password?token=') && str_contains($url, 'email=sam%40example.com');
        });
        $this->assertSame(1, AuditLogEntry::where('action', 'password.reset_requested')->count());
    }

    public function test_an_unknown_address_gets_the_same_answer_and_nothing_is_sent(): void
    {
        Notification::fake();

        $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com'])->assertNoContent();

        Notification::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password_and_signs_out_everywhere(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com', 'password' => 'Old-Password-123']);
        // A Revenue app session and a Web tool (Hub OIDC) token.
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        [$client, $secret] = $this->makeWebClient();
        $tokens = $this->authorizeAndExchange($user->refresh(), $client, $secret, 'openid');
        $token = Password::broker()->createToken($user);

        $this->reset(['token' => $token, 'email' => 'Sam@Example.com'])->assertNoContent();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
            'client_id' => $client->id, 'client_secret' => $secret])->assertStatus(400);
        $this->assertSame(1, AuditLogEntry::where('action', 'password.reset')->count());

        $this->postJson('/api/login', ['email' => 'sam@example.com', 'password' => self::NEW_PASSWORD], ['Origin' => 'http://localhost'])->assertOk();
    }

    public function test_a_link_works_once(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        $token = Password::broker()->createToken($user);

        $this->reset(['token' => $token, 'email' => 'sam@example.com'])->assertNoContent();
        $this->reset(['token' => $token, 'email' => 'sam@example.com'])
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/reset_link_invalid');
    }

    public function test_wrong_tokens_and_unknown_addresses_get_the_same_answer(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com', 'password' => 'Old-Password-123']);
        Password::broker()->createToken($user);

        $wrong = $this->reset(['token' => 'not-the-token', 'email' => 'sam@example.com'])->assertStatus(422)->json();
        $unknown = $this->reset(['token' => 'not-the-token', 'email' => 'nobody@example.com'])->assertStatus(422)->json();

        $this->assertSame($wrong, $unknown);
        $this->assertTrue(Hash::check('Old-Password-123', $user->refresh()->password));
    }

    public function test_new_passwords_follow_the_rules(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password/reset', ['token' => $token, 'email' => 'sam@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/validation_failed')->assertJsonValidationErrors('password');
    }

    public function test_requests_are_rate_limited(): void
    {
        Notification::fake();
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/password/forgot', ['email' => "x{$i}@example.com"])->assertNoContent();
        }
        $this->postJson('/api/password/forgot', ['email' => 'x6@example.com'])->assertStatus(429);
    }
}
