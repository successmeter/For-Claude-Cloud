<?php
// api/app/Services/Invitations/InvitationTokens.php
namespace App\Services\Invitations;

use Illuminate\Support\Str;

/**
 * Invitation tokens are "{org_id}.{secret}" (Plan D design §3.1). The org id lets the
 * unauthenticated accept request run inside that org's tenant context and find the row under RLS;
 * the secret is 32 random bytes, base64url, and only its SHA-256 is stored.
 */
class InvitationTokens
{
    /** @return array{0: string, 1: string} [token to send, hash to store] */
    public function issue(string $orgId): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ["{$orgId}.{$secret}", $this->hash($secret)];
    }

    /** @return array{0: string, 1: string}|null [org id, secret] */
    public function parse(string $token): ?array
    {
        if (! preg_match('/^([0-9a-f-]{36})\.([A-Za-z0-9_-]{43})$/', $token, $m) || ! Str::isUuid($m[1])) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
