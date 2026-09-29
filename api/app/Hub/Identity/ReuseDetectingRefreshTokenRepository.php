<?php
// api/app/Hub/Identity/ReuseDetectingRefreshTokenRepository.php
namespace App\Hub\Identity;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

/**
 * Refresh-token reuse detection (design §4.1). Passport already rotates refresh tokens and rejects
 * a revoked one; it does not treat that as theft. Here, presenting a revoked refresh token revokes
 * every access and refresh token of that user for that client.
 *
 * "Family" is (user, client), not a per-grant lineage: simpler, stricter, and every Plan B client
 * holds one grant per user session. Bound in AppServiceProvider; both the OIDC package's
 * auth-code grant and Passport's refresh grant resolve this repository from the container.
 */
class ReuseDetectingRefreshTokenRepository extends RefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $revoked = parent::isRefreshTokenRevoked($tokenId);

        if ($revoked) {
            $this->revokeFamily($tokenId);
        }

        return $revoked;
    }

    private function revokeFamily(string $tokenId): void
    {
        $refreshToken = Passport::refreshToken()->newQuery()->find($tokenId);
        $accessToken = $refreshToken ? Passport::token()->newQuery()->find($refreshToken->access_token_id) : null;
        if (! $accessToken) {
            return; // unknown or already purged: nothing to link it to
        }

        $familyIds = Passport::token()->newQuery()
            ->where('user_id', $accessToken->user_id)
            ->where('client_id', $accessToken->client_id)
            ->pluck('id');

        Passport::token()->newQuery()->whereIn('id', $familyIds)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $familyIds)->update(['revoked' => true]);

        app(AuditLogger::class)->record(
            'oauth.refresh_reuse',
            'oauth_client',
            (string) $accessToken->client_id,
            null,
            ['user_public_id' => User::find($accessToken->user_id)?->public_id],
        );
    }
}
