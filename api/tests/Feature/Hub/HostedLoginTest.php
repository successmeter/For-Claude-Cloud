<?php
// api/tests/Feature/Hub/HostedLoginTest.php

namespace Tests\Feature\Hub;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

/**
 * The browser side of /oauth/authorize: an unauthenticated user is sent to the Hub's own login
 * page (and MFA step), then back to authorize.
 */
class HostedLoginTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    private const PASSWORD = 'correct-horse-Battery-9';

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
    }

    private function user(bool $mfa = false): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        if ($mfa) {
            $user->forceFill(['mfa_secret' => (new Google2FA)->generateSecretKey(), 'mfa_enabled' => true])->save();
        }

        return $user;
    }

    private function startAuthorize(): string
    {
        [$client] = $this->makeWebClient();
        [, $challenge] = $this->pkce();
        $url = $this->authorizeQuery($client, $challenge, 'openid');

        $this->get($url)->assertRedirect(route('login'));

        return $url;
    }

    /** Same path and same query parameters; Laravel's intended() URL reorders the query string. */
    private function assertRedirectsTo(string $expected, $response): void
    {
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertSame(parse_url($expected, PHP_URL_PATH), parse_url($location, PHP_URL_PATH));
        $want = $this->redirectParams($expected);
        $got = $this->redirectParams($location);
        ksort($want);
        ksort($got);
        $this->assertSame($want, $got);
    }

    public function test_login_page_renders_with_csrf_field(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false)->assertSee('name="password"', false);
    }

    public function test_password_login_returns_to_authorize_and_yields_a_code(): void
    {
        $user = $this->user();
        $authorize = $this->startAuthorize();

        $this->assertRedirectsTo($authorize, $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]));
        $this->assertAuthenticatedAs($user, 'web');

        $location = $this->get($authorize)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::WEB_REDIRECT, $location);
        $this->assertArrayHasKey('code', $this->redirectParams($location));

        $entry = AuditLogEntry::where('action', 'login')->latest('id')->first();
        $this->assertSame(['channel' => 'oidc'], $entry->meta);
    }

    public function test_wrong_password_is_refused_with_the_same_message_as_the_json_login(): void
    {
        $user = $this->user();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest('web');
    }

    public function test_mfa_user_must_pass_the_totp_step(): void
    {
        $user = $this->user(mfa: true);
        $authorize = $this->startAuthorize();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('login.mfa'));
        $this->assertGuest('web');

        $this->from('/login/mfa')->post('/login/mfa', ['code' => '000000'])
            ->assertRedirect('/login/mfa')
            ->assertSessionHasErrors('code');
        $this->assertGuest('web');

        $code = (new Google2FA)->getCurrentOtp($user->mfa_secret);
        $this->assertRedirectsTo($authorize, $this->post('/login/mfa', ['code' => $code]));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_mfa_step_without_a_pending_login_goes_back_to_login(): void
    {
        $this->get('/login/mfa')->assertRedirect(route('login'));
        $this->post('/login/mfa', ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_expired_pending_mfa_state_goes_back_to_login(): void
    {
        $user = $this->user(mfa: true);
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->travel(6)->minutes();

        $code = (new Google2FA)->getCurrentOtp($user->mfa_secret);
        $this->post('/login/mfa', ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_session_id_rotates_on_login(): void
    {
        $user = $this->user();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_ends_the_hub_session(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web')->post('/logout')->assertRedirect('/');

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_authorize_requires_s256_pkce(): void
    {
        [$client] = $this->makeWebClient();
        [, $challenge] = $this->pkce();
        $user = $this->user();

        $this->actingAs($user, 'web')
            ->get($this->authorizeQuery($client, $challenge, 'openid', ['code_challenge_method' => 'plain']))
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);

        $url = $this->authorizeQuery($client, $challenge, 'openid');
        $withoutChallenge = preg_replace('/&code_challenge=[^&]*/', '', $url);
        $this->actingAs($user, 'web')->get($withoutChallenge)->assertStatus(400);
    }
}
