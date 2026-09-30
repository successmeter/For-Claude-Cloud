<?php
// api/tests/Feature/Hub/RefreshTokenReuseTest.php

namespace Tests\Feature\Hub;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

/**
 * Refresh tokens rotate on use. Presenting an already-rotated token means it was copied, so every
 * token of that user for that client is revoked (design §4.1): the thief and the legitimate
 * client both lose access, and the user signs in again.
 */
class RefreshTokenReuseTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
    }

    private function refresh(string $clientId, string $secret, string $refreshToken)
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $secret,
            'refresh_token' => $refreshToken,
        ]);
    }

    public function test_replaying_a_rotated_refresh_token_revokes_the_whole_family(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $user = User::factory()->create()->refresh();
        $first = $this->authorizeAndExchange($user, $client, $secret, 'openid');
        Auth::forgetGuards();

        $second = $this->refresh($client->id, $secret, $first['refresh_token'])->assertOk()->json();

        // Replay of the rotated token: rejected...
        $this->refresh($client->id, $secret, $first['refresh_token'])->assertStatus(400);
        // ...and the legitimate successor is dead too.
        $this->refresh($client->id, $secret, $second['refresh_token'])->assertStatus(400);
        Auth::forgetGuards();
        $this->withToken($second['access_token'])->getJson('/oauth/userinfo')->assertUnauthorized();

        $entry = AuditLogEntry::where('action', 'oauth.refresh_reuse')->first();
        $this->assertNotNull($entry);
        $this->assertSame((string) $client->id, $entry->entity_id);
        $this->assertSame($user->public_id, $entry->meta['user_public_id']);
    }

    public function test_normal_rotation_keeps_working(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $user = User::factory()->create()->refresh();
        $tokens = $this->authorizeAndExchange($user, $client, $secret, 'openid');

        for ($i = 0; $i < 3; $i++) {
            $tokens = $this->refresh($client->id, $secret, $tokens['refresh_token'])->assertOk()->json();
        }

        $this->assertSame(0, AuditLogEntry::where('action', 'oauth.refresh_reuse')->count());
    }

    public function test_reuse_does_not_touch_other_users_or_clients(): void
    {
        [$client, $secret] = $this->makeWebClient();
        [$otherClient, $otherSecret] = $this->makeWebClient(['name' => 'other']);
        $user = User::factory()->create()->refresh();
        $otherUser = User::factory()->create()->refresh();

        $victim = $this->authorizeAndExchange($user, $client, $secret, 'openid');
        $sameUserOtherClient = $this->authorizeAndExchange($user, $otherClient, $otherSecret, 'openid');
        $otherUsersTokens = $this->authorizeAndExchange($otherUser, $client, $secret, 'openid');
        Auth::forgetGuards();

        $this->refresh($client->id, $secret, $victim['refresh_token'])->assertOk();
        $this->refresh($client->id, $secret, $victim['refresh_token'])->assertStatus(400);

        $this->refresh($otherClient->id, $otherSecret, $sameUserOtherClient['refresh_token'])->assertOk();
        $this->refresh($client->id, $secret, $otherUsersTokens['refresh_token'])->assertOk();
    }
}
