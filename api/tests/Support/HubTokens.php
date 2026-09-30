<?php
// api/tests/Support/HubTokens.php

namespace Tests\Support;

use App\Hub\Identity\FirstPartyClient;
use App\Hub\Models\OrgToolLink;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;

/**
 * Drives the Hub's OAuth/OIDC server the way the Web tool does: a confidential first-party client,
 * authorization code + PKCE (S256).
 */
trait HubTokens
{
    protected const WEB_REDIRECT = 'https://web.test/auth/callback';

    /** @return array{0: FirstPartyClient, 1: string} the client and its plaintext secret */
    protected function makeWebClient(array $overrides = []): array
    {
        $secret = Str::random(40);
        $client = FirstPartyClient::forceCreate($overrides + [
            'name' => 'web',
            'secret' => $secret,
            'provider' => 'users',
            'redirect_uris' => [self::WEB_REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'revoked' => false,
            'hub_tool' => 'web',
        ]);

        return [$client, $secret];
    }

    /** @return array{0: string, 1: string} verifier and S256 challenge */
    protected function pkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    protected function authorizeQuery(FirstPartyClient $client, string $challenge, string $scope, array $extra = []): string
    {
        return '/oauth/authorize?'.http_build_query($extra + [
            'client_id' => $client->id,
            'redirect_uri' => self::WEB_REDIRECT,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => 'state-123',
            'nonce' => 'nonce-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Extracts the query parameters of a redirect Location header. */
    protected function redirectParams(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        return $params;
    }

    /**
     * Full flow for an already signed-in user: authorize, then exchange the code.
     *
     * @return array the token endpoint's JSON response
     */
    protected function authorizeAndExchange(User $user, FirstPartyClient $client, string $secret, string $scope = 'openid'): array
    {
        $this->forgetRequestState();
        [$verifier, $challenge] = $this->pkce();

        $location = $this->actingAs($user, 'web')
            ->get($this->authorizeQuery($client, $challenge, $scope))
            ->assertRedirect()
            ->headers->get('Location');

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $secret,
            'redirect_uri' => self::WEB_REDIRECT,
            'code' => $this->redirectParams($location)['code'],
            'code_verifier' => $verifier,
        ])->assertOk()->json();
    }

    /**
     * Requests in one test share an application instance, and Laravel caches each route's
     * controller on the Route object. Passport's AuthorizationController keeps the guard it was
     * built with, so without this a second authorize in the same test would issue a code for
     * whichever user that stale guard last held. (A real deployment builds a fresh app per
     * request; see the Octane note in Plan A's open items.)
     */
    protected function forgetRequestState(): void
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->controller = null;
        }
    }

    /**
     * An access token for $user through $client (authorization code flow). The session user the
     * flow needed is forgotten afterwards, so later requests authenticate by the token alone.
     */
    protected function userToken(User $user, FirstPartyClient $client, string $secret, string $scope): string
    {
        $token = $this->authorizeAndExchange($user, $client, $secret, $scope)['access_token'];
        Auth::forgetGuards();

        return $token;
    }

    /** A tool's own access token (client credentials, no user). */
    protected function toolToken(FirstPartyClient $client, string $secret, string $scope): string
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $secret,
            'scope' => $scope,
        ])->assertOk()->json('access_token');
    }

    protected function makeOrg(string $name = 'Org'): Org
    {
        return Org::create(['name' => $name]);
    }

    /** A user (refreshed, so public_id is loaded) with $role in $org. */
    protected function makeMember(Org $org, string $role, bool $mfa = false): User
    {
        $user = User::factory()->create();
        if ($mfa) {
            $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        }
        TenantContext::run($org->id, fn () => Membership::create([
            'org_id' => $org->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user->refresh();
    }

    protected function linkTool(Org $org, string $tool = 'web'): void
    {
        TenantContext::run($org->id, fn () => OrgToolLink::create([
            'org_id' => $org->id, 'tool' => $tool, 'external_tenant_ref' => 'tenant-'.$org->id, 'linked_at' => now(),
        ]));
    }

    protected function parseJwt(string $jwt): UnencryptedToken
    {
        return (new Parser(new JoseEncoder))->parse($jwt);
    }
}
