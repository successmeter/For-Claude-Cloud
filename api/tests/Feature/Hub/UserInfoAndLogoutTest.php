<?php
// api/tests/Feature/Hub/UserInfoAndLogoutTest.php

namespace Tests\Feature\Hub;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

class UserInfoAndLogoutTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    private const POST_LOGOUT = 'https://web.test/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
    }

    public function test_discovery_advertises_userinfo_and_end_session(): void
    {
        $this->get('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('userinfo_endpoint', url('/oauth/userinfo'))
            ->assertJsonPath('end_session_endpoint', url('/oauth/logout'));
    }

    public function test_userinfo_sub_is_the_public_id_never_the_internal_id(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $user = User::factory()->create()->refresh();
        $tokens = $this->authorizeAndExchange($user, $client, $secret, 'openid');
        Auth::forgetGuards();

        $body = $this->withToken($tokens['access_token'])->getJson('/oauth/userinfo')->assertOk()->json();

        $this->assertSame($user->public_id, $body['sub']);
        $this->assertNotSame((string) $user->id, $body['sub']);
        $this->assertArrayNotHasKey('email', $body);
        $this->assertArrayNotHasKey('name', $body);
    }

    public function test_userinfo_returns_claims_allowed_by_scopes(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $user = User::factory()->create()->refresh();
        $tokens = $this->authorizeAndExchange($user, $client, $secret, 'openid email profile');
        Auth::forgetGuards();

        $this->withToken($tokens['access_token'])->getJson('/oauth/userinfo')
            ->assertOk()
            ->assertExactJson([
                'sub' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => true,
            ]);
    }

    public function test_userinfo_requires_a_token(): void
    {
        $this->getJson('/oauth/userinfo')->assertUnauthorized();
    }

    private function signedInWithIdToken(): array
    {
        [$client, $secret] = $this->makeWebClient(['post_logout_redirect_uris' => [self::POST_LOGOUT]]);
        $user = User::factory()->create()->refresh();
        $tokens = $this->authorizeAndExchange($user, $client, $secret, 'openid');

        return [$user, $tokens['id_token']];
    }

    public function test_logout_ends_the_session_and_redirects_to_a_registered_uri(): void
    {
        [$user, $idToken] = $this->signedInWithIdToken();

        $this->actingAs($user, 'web')
            ->get('/oauth/logout?'.http_build_query([
                'id_token_hint' => $idToken,
                'post_logout_redirect_uri' => self::POST_LOGOUT,
                'state' => 'bye',
            ]))
            ->assertRedirect(self::POST_LOGOUT.'?state=bye');

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_unregistered_post_logout_uri_is_refused_without_redirect(): void
    {
        [$user, $idToken] = $this->signedInWithIdToken();

        $this->actingAs($user, 'web')
            ->get('/oauth/logout?'.http_build_query([
                'id_token_hint' => $idToken,
                'post_logout_redirect_uri' => 'https://evil.test/',
            ]))
            ->assertStatus(400);
    }

    public function test_tampered_id_token_hint_is_refused(): void
    {
        [$user, $idToken] = $this->signedInWithIdToken();
        [$header, $payload, $signature] = explode('.', $idToken);
        $forged = $header.'.'.$payload.'.'.strrev($signature);

        $this->actingAs($user, 'web')
            ->get('/oauth/logout?'.http_build_query([
                'id_token_hint' => $forged,
                'post_logout_redirect_uri' => self::POST_LOGOUT,
            ]))
            ->assertStatus(400);
    }

    public function test_missing_hint_is_refused(): void
    {
        $this->get('/oauth/logout?'.http_build_query(['post_logout_redirect_uri' => self::POST_LOGOUT]))
            ->assertStatus(400);
    }
}
