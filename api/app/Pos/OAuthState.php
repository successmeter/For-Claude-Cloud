<?php
// api/app/Pos/OAuthState.php
namespace App\Pos;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The OAuth `state` for connecting a POS: who asked, for which org, until when, sealed with the app
 * key (authenticated encryption, so it cannot be forged or edited) and usable once. It does not rely
 * on the browser's session: Square sends the owner back to the Hub's host, which is not the app's.
 */
class OAuthState
{
    public const TTL_MINUTES = 10;

    public function issue(string $provider, string $orgId, int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => "pos_connect:{$provider}",
            'org_id' => $orgId,
            'user_id' => $userId,
            'nonce' => Str::random(32),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]));
    }

    /** @return array{org_id: string, user_id: int}|null null when forged, expired, for another provider or already used */
    public function consume(string $provider, mixed $state): ?array
    {
        if (! is_string($state) || $state === '' || strlen($state) > 4096) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }
        if (($data['purpose'] ?? null) !== "pos_connect:{$provider}" || ($data['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        if (! Cache::add('pos-oauth-state:'.$data['nonce'], true, now()->addMinutes(self::TTL_MINUTES + 1))) {
            return null;
        }

        return ['org_id' => (string) $data['org_id'], 'user_id' => (int) $data['user_id']];
    }
}
