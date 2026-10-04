<?php
// api/tests/Feature/Pos/SquareConnectTest.php

namespace Tests\Feature\Pos;

use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Services\Encryption\EnvelopeEncryptor;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan E Task 5: connecting Square (design E1, §2.5, §3 OAuth, §6). */
class SquareConnectTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private const BASE = 'https://connect.squareupsandbox.com';

    private Org $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.square.application_id' => 'sandbox-sq0idb-app',
            'services.square.application_secret' => 'sandbox-sq0csb-secret',
            'services.square.environment' => 'sandbox',
            'services.square.redirect_uri' => 'https://hub.example.test/api/pos/square/callback',
            'app.frontend_url' => 'https://app.example.test',
        ]);
        Http::preventStrayRequests();
        $this->org = $this->org();
        $this->owner = $this->member($this->org);
        $this->owner->forceFill(['mfa_enabled' => true])->save();
    }

    private function fakeSquare(int $revokeStatus = 200): void
    {
        Http::fake([
            self::BASE.'/oauth2/token' => Http::response([
                'access_token' => 'EAAAl-access-1', 'token_type' => 'bearer', 'expires_at' => '2026-11-01T00:00:00Z',
                'merchant_id' => 'MERCHANT1', 'refresh_token' => 'EQAAl-refresh-1', 'short_lived' => false,
            ]),
            self::BASE.'/oauth2/revoke' => Http::response(['success' => $revokeStatus === 200], $revokeStatus),
        ]);
    }

    /** Starts a connection as $user and returns the state Square would send back. */
    private function connect(?User $user = null): string
    {
        $url = $this->spa($user ?? $this->owner, $this->org, 'POST', '/api/pos/square/connect')->assertOk()->json('authorize_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function squareCallback(array $query)
    {
        return $this->get('/api/pos/square/callback?'.http_build_query($query));
    }

    private function connection(): ?object
    {
        return $this->inTenant($this->org, fn () => DB::table('pos_connections')->first());
    }

    public function test_the_authorize_url_asks_for_read_only_scopes(): void
    {
        $url = $this->spa($this->owner, $this->org, 'POST', '/api/pos/square/connect')->assertOk()->json('authorize_url');

        $this->assertStringStartsWith(self::BASE.'/oauth2/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('sandbox-sq0idb-app', $query['client_id']);
        $this->assertSame('MERCHANT_PROFILE_READ ORDERS_READ ITEMS_READ', $query['scope']);
        $this->assertSame('false', $query['session']);
        $this->assertSame('https://hub.example.test/api/pos/square/callback', $query['redirect_uri']);
        $this->assertGreaterThan(40, strlen($query['state']));
    }

    public function test_the_callback_stores_encrypted_tokens_and_returns_to_the_app(): void
    {
        $this->fakeSquare();
        Log::spy();

        $this->squareCallback(['code' => 'sq0cgb-code', 'state' => $this->connect()])
            ->assertRedirect('https://app.example.test/data?square=connected');

        $row = $this->connection();
        $this->assertSame(['square', 'MERCHANT1', 'connected', $this->owner->id], [$row->provider, $row->merchant_id, $row->status, $row->connected_by]);
        $this->assertStringNotContainsString('EAAAl-access-1', $row->access_token_enc);
        $this->assertStringNotContainsString('EQAAl-refresh-1', $row->refresh_token_enc);
        $encryptor = app(EnvelopeEncryptor::class);
        $this->assertSame('EAAAl-access-1', $encryptor->decrypt($this->org->id, $row->access_token_enc));
        $this->assertSame('EQAAl-refresh-1', $encryptor->decrypt($this->org->id, $row->refresh_token_enc));
        $this->assertSame('MERCHANT_PROFILE_READ ORDERS_READ ITEMS_READ', $row->scopes);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/oauth2/token'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === 'sq0cgb-code' && $r['client_secret'] === 'sandbox-sq0csb-secret'
            && $r['redirect_uri'] === 'https://hub.example.test/api/pos/square/callback');
        $audit = AuditLogEntry::where('action', 'pos.connected')->sole();
        $this->assertEquals(['provider' => 'square', 'merchant_id' => 'MERCHANT1'], $audit->meta);
        $this->assertStringNotContainsString('EAAAl', json_encode(AuditLogEntry::all()->toArray()));
        Log::shouldNotHaveReceived('info');
    }

    public function test_status_never_shows_tokens(): void
    {
        $this->fakeSquare();
        $this->squareCallback(['code' => 'c', 'state' => $this->connect()]);

        $response = $this->spa($this->member($this->org, 'viewer'), $this->org, 'GET', '/api/pos')->assertOk();
        $response->assertJson(['square' => ['available' => true, 'status' => 'connected', 'merchant_id' => 'MERCHANT1', 'locations_linked' => 0,
            'last_synced_at' => null, 'last_error' => null, 'paused' => false]]);
        $this->assertStringNotContainsString('EAAAl', $response->getContent());
        $this->assertStringNotContainsString('EQAAl', $response->getContent());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_status_before_connecting_and_when_square_is_not_configured(): void
    {
        $this->spa($this->owner, $this->org, 'GET', '/api/pos')->assertOk()
            ->assertExactJson(['square' => ['available' => true, 'status' => 'not_connected']]);

        config(['services.square.application_id' => null]);
        $this->spa($this->owner, $this->org, 'GET', '/api/pos')->assertOk()
            ->assertExactJson(['square' => ['available' => false, 'status' => 'not_connected']]);
        $this->spa($this->owner, $this->org, 'POST', '/api/pos/square/connect')->assertStatus(409)
            ->assertJsonPath('type', 'https://hub/problems/square_not_configured');
    }

    public function test_a_state_works_once(): void
    {
        $this->fakeSquare();
        $state = $this->connect();

        $this->squareCallback(['code' => 'c', 'state' => $state])->assertRedirect('https://app.example.test/data?square=connected');
        $this->squareCallback(['code' => 'c', 'state' => $state])->assertRedirect('https://app.example.test/data?square=failed');
        Http::assertSentCount(1);
    }

    public function test_a_forged_tampered_or_expired_state_is_refused(): void
    {
        $this->fakeSquare();

        $this->squareCallback(['code' => 'c', 'state' => 'forged'])->assertRedirect('https://app.example.test/data?square=failed');
        $this->squareCallback(['code' => 'c', 'state' => substr($this->connect(), 0, -4).'AAAA'])->assertRedirect('https://app.example.test/data?square=failed');
        $this->squareCallback(['code' => 'c'])->assertRedirect('https://app.example.test/data?square=failed');

        $state = $this->connect();
        $this->travel(11)->minutes();
        $this->squareCallback(['code' => 'c', 'state' => $state])->assertRedirect('https://app.example.test/data?square=failed');

        Http::assertNothingSent();
        $this->assertNull($this->connection());
    }

    public function test_the_owner_declining_at_square_is_reported(): void
    {
        $this->fakeSquare();

        $this->squareCallback(['error' => 'access_denied', 'error_description' => 'user denied', 'state' => $this->connect()])
            ->assertRedirect('https://app.example.test/data?square=denied');
        Http::assertNothingSent();
    }

    public function test_a_failed_token_exchange_stores_nothing(): void
    {
        Http::fake([self::BASE.'/oauth2/token' => Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401)]);

        $this->squareCallback(['code' => 'c', 'state' => $this->connect()])->assertRedirect('https://app.example.test/data?square=failed');
        $this->assertNull($this->connection());
    }

    public function test_the_initiator_must_still_be_an_owner_with_mfa_when_square_answers(): void
    {
        $this->fakeSquare();
        $state = $this->connect();
        $this->owner->forceFill(['mfa_enabled' => false])->save();

        $this->squareCallback(['code' => 'c', 'state' => $state])->assertRedirect('https://app.example.test/data?square=failed');
        $this->assertNull($this->connection());
    }

    public function test_only_owners_with_mfa_connect_and_disconnect(): void
    {
        $manager = $this->member($this->org, 'manager');
        $this->spa($manager, $this->org, 'POST', '/api/pos/square/connect')->assertForbidden();
        $this->spa($manager, $this->org, 'DELETE', '/api/pos/square')->assertForbidden();

        $this->owner->forceFill(['mfa_enabled' => false])->save();
        $this->spa($this->owner, $this->org, 'POST', '/api/pos/square/connect')
            ->assertForbidden()->assertJsonPath('type', 'https://hub/problems/mfa_required');
    }

    public function test_disconnecting_revokes_at_square_and_deletes_the_tokens(): void
    {
        $this->fakeSquare();
        $this->squareCallback(['code' => 'c', 'state' => $this->connect()]);

        $this->spa($this->owner, $this->org, 'DELETE', '/api/pos/square')->assertNoContent();

        $this->assertNull($this->connection());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/oauth2/revoke'
            && $r->header('Authorization') === ['Client sandbox-sq0csb-secret']
            && $r['client_id'] === 'sandbox-sq0idb-app' && $r['access_token'] === 'EAAAl-access-1');
        $this->assertSame(1, AuditLogEntry::where('action', 'pos.disconnected')->count());
        $this->spa($this->owner, $this->org, 'DELETE', '/api/pos/square')->assertNotFound();
    }

    public function test_a_failed_revoke_still_deletes_the_tokens(): void
    {
        $this->fakeSquare(revokeStatus: 500);
        $this->squareCallback(['code' => 'c', 'state' => $this->connect()]);

        $this->spa($this->owner, $this->org, 'DELETE', '/api/pos/square')->assertNoContent();

        $this->assertNull($this->connection());
        $this->assertEquals(['provider' => 'square', 'revoked' => false], AuditLogEntry::where('action', 'pos.disconnected')->sole()->meta);
    }

    public function test_connections_are_limited_to_their_org(): void
    {
        $this->fakeSquare();
        $this->squareCallback(['code' => 'c', 'state' => $this->connect()]);
        $other = $this->org('Other');
        $stranger = $this->member($other);
        $stranger->forceFill(['mfa_enabled' => true])->save();

        $this->spa($stranger, $other, 'GET', '/api/pos')->assertJsonPath('square.status', 'not_connected');
        $this->spa($stranger, $other, 'DELETE', '/api/pos/square')->assertNotFound();
        $this->assertNotNull($this->connection());
    }

    public function test_reconnecting_replaces_the_tokens(): void
    {
        $this->fakeSquare();
        $this->squareCallback(['code' => 'c', 'state' => $this->connect()]);
        $first = $this->connection();
        $this->squareCallback(['code' => 'c2', 'state' => $this->connect()]);

        $this->assertSame(1, $this->inTenant($this->org, fn () => DB::table('pos_connections')->count()));
        $this->assertSame($first->id, $this->connection()->id, 'the same merchant keeps its connection and links');
    }
}
