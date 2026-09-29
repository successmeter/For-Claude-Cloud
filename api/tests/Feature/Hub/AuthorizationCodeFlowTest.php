<?php
// api/tests/Feature/Hub/AuthorizationCodeFlowTest.php

namespace Tests\Feature\Hub;

use App\Models\User;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

/**
 * The OIDC core the Web tool depends on. Also the upgrade gate for laravel/passport and
 * jeremy379/laravel-openid-connect (Plan B open item 1): if a package update breaks the flow,
 * this fails.
 */
class AuthorizationCodeFlowTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
    }

    public function test_code_flow_with_pkce_issues_an_id_token_keyed_by_public_id(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $user = User::factory()->create()->refresh();
        [, $challenge] = $this->pkce();

        $params = $this->redirectParams(
            $this->actingAs($user, 'web')->get($this->authorizeQuery($client, $challenge, 'openid'))
                ->assertRedirect()->headers->get('Location'),
        );
        $this->assertSame('state-123', $params['state']);
        $this->assertNotEmpty($params['code']);

        $tokens = $this->authorizeAndExchange($user, $client, $secret, 'openid');

        $this->assertArrayHasKey('id_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertLessThanOrEqual(900, $tokens['expires_in'], 'access tokens live 15 minutes, not Passport\'s 1-year default');

        $id = $this->parseJwt($tokens['id_token'])->claims();
        $this->assertSame($user->public_id, $id->get('sub'));
        $this->assertNotSame((string) $user->id, $id->get('sub'));
        $this->assertSame('nonce-123', $id->get('nonce'));
        $this->assertSame([$client->id], $id->get('aud'));
        // No microseconds: iat is a whole second.
        $this->assertSame('000000', $id->get('iat')->format('u'));
    }

    public function test_discovery_and_jwks_are_published(): void
    {
        $discovery = $this->get('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonStructure(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'])
            ->json();

        $this->assertEqualsCanonicalizing(
            ['openid', 'profile', 'email', 'orgs', 'competitor-sets:read', 'competitor-sets:write'],
            $discovery['scopes_supported'],
        );

        $this->get('/oauth/jwks')->assertOk()->assertJsonStructure(['keys' => [['kty', 'n', 'e']]]);
    }

    public function test_client_credentials_grant_works_for_the_web_client(): void
    {
        [$client, $secret] = $this->makeWebClient();

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $secret,
        ])->assertOk()->assertJsonStructure(['access_token', 'expires_in']);
    }

    public function test_unknown_redirect_uri_is_refused(): void
    {
        [$client] = $this->makeWebClient();
        [, $challenge] = $this->pkce();
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->get(
            $this->authorizeQuery($client, $challenge, 'openid', ['redirect_uri' => 'https://evil.test/cb']),
        );

        $this->assertNotSame(302, $response->getStatusCode(), 'must never redirect to an unregistered URI');
    }
}
