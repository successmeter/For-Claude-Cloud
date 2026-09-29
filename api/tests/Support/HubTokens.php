<?php
// api/tests/Support/HubTokens.php

namespace Tests\Support;

use App\Hub\Identity\FirstPartyClient;
use App\Models\User;
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

    protected function parseJwt(string $jwt): UnencryptedToken
    {
        return (new Parser(new JoseEncoder))->parse($jwt);
    }
}
