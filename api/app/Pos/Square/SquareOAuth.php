<?php
// api/app/Pos/Square/SquareOAuth.php
namespace App\Pos\Square;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Square's OAuth endpoints (Plan E design §3): read-only scopes, the code exchange, token refresh
 * and revocation. Tokens pass through here and nowhere else in plain text; they are never logged.
 */
class SquareOAuth
{
    public const SCOPES = ['MERCHANT_PROFILE_READ', 'ORDERS_READ', 'ITEMS_READ'];

    public static function configured(): bool
    {
        return filled(config('services.square.application_id')) && filled(config('services.square.application_secret'));
    }

    public static function baseUrl(): string
    {
        return config('services.square.environment') === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    }

    public function authorizeUrl(string $state): string
    {
        return self::baseUrl().'/oauth2/authorize?'.http_build_query([
            'client_id' => config('services.square.application_id'),
            'scope' => implode(' ', self::SCOPES),
            // Always show Square's sign-in, so the owner picks the account deliberately.
            'session' => 'false',
            'state' => $state,
            'redirect_uri' => config('services.square.redirect_uri'),
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?CarbonImmutable, merchant_id: string}
     *
     * @throws SquareAuthFailed
     */
    public function exchange(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('services.square.redirect_uri')]);
    }

    /** @throws SquareAuthFailed when Square refuses the refresh token (the owner must reconnect) */
    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** Best effort: false when Square could not be reached or refused. */
    public function revoke(string $accessToken): bool
    {
        try {
            return Http::withHeaders(['Authorization' => 'Client '.config('services.square.application_secret'), 'Square-Version' => config('services.square.api_version')])
                ->timeout(10)->acceptJson()
                ->post(self::baseUrl().'/oauth2/revoke', ['client_id' => config('services.square.application_id'), 'access_token' => $accessToken])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function token(array $grant): array
    {
        try {
            $response = Http::withHeaders(['Square-Version' => config('services.square.api_version')])->timeout(15)->acceptJson()
                ->post(self::baseUrl().'/oauth2/token', [
                    'client_id' => config('services.square.application_id'),
                    'client_secret' => config('services.square.application_secret'),
                ] + $grant);
        } catch (\Throwable $e) {
            throw new SquareUnavailable('Square could not be reached.', previous: null);
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new SquareUnavailable("Square answered {$response->status()}.");
        }
        $body = $response->json();
        if (! $response->successful() || ! is_string($body['access_token'] ?? null) || ! is_string($body['merchant_id'] ?? null)) {
            throw new SquareAuthFailed("Square refused the grant ({$response->status()}).");
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : null,
            'expires_at' => isset($body['expires_at']) ? CarbonImmutable::parse($body['expires_at']) : null,
            'merchant_id' => $body['merchant_id'],
        ];
    }
}
