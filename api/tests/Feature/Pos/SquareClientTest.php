<?php
// api/tests/Feature/Pos/SquareClientTest.php

namespace Tests\Feature\Pos;

use App\Models\Org;
use App\Pos\Square\SquareAuthFailed;
use App\Pos\Square\SquareClient;
use App\Pos\Square\SquareRequestFailed;
use App\Pos\Square\SquareUnavailable;
use App\Services\Encryption\EnvelopeEncryptor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\Support\SquareFixtures;
use Tests\TestCase;

/** Plan E Task 6: the Square API client. */
class SquareClientTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests, SquareFixtures;

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSquare();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->org = $this->org();
    }

    private function client(): ?SquareClient
    {
        return $this->inTenant($this->org, fn () => SquareClient::forOrg());
    }

    /** Runs $fn with the client inside tenant context, as the sync job does. */
    private function withClient(\Closure $fn): mixed
    {
        return $this->inTenant($this->org, fn () => $fn(SquareClient::forOrg()));
    }

    public function test_no_connection_no_client(): void
    {
        $this->assertNull($this->client());
    }

    public function test_requests_carry_the_token_and_version_and_pages_are_followed(): void
    {
        $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/orders/search' => Http::sequence()
            ->push(['orders' => [['id' => 'o1'], ['id' => 'o2']], 'cursor' => 'next'])
            ->push(['orders' => [['id' => 'o3']]])]);

        $ids = $this->withClient(fn (SquareClient $c) => array_column(iterator_to_array($c->completedOrders('L1',
            CarbonImmutable::parse('2026-03-01T00:00:00+08:00'), CarbonImmutable::parse('2026-03-02T00:00:00+08:00')), false), 'id'));

        $this->assertSame(['o1', 'o2', 'o3'], $ids);
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer access-1') && $r->hasHeader('Square-Version')
            && $r['location_ids'] === ['L1'] && ! isset($r['cursor'])
            && $r['query']['filter']['date_time_filter']['closed_at'] === ['start_at' => '2026-02-28T16:00:00Z', 'end_at' => '2026-03-01T16:00:00Z']
            && $r['query']['filter']['state_filter']['states'] === ['COMPLETED']);
        Http::assertSent(fn (HttpRequest $r) => ($r['cursor'] ?? null) === 'next');
    }

    public function test_rate_limits_and_server_errors_are_retried_with_backoff(): void
    {
        $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/locations' => Http::sequence()
            ->push([], 429)->push([], 503)->push(['locations' => [['id' => 'L1', 'name' => 'Main', 'timezone' => 'Australia/Perth', 'status' => 'ACTIVE']]])]);

        $locations = $this->withClient(fn (SquareClient $c) => $c->locations());

        $this->assertSame([['id' => 'L1', 'name' => 'Main', 'timezone' => 'Australia/Perth', 'status' => 'ACTIVE']], $locations);
        Sleep::assertSleptTimes(2);
    }

    public function test_it_gives_up_after_four_attempts(): void
    {
        $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/locations' => Http::response([], 500)]);

        $this->expectException(SquareUnavailable::class);
        try {
            $this->withClient(fn (SquareClient $c) => $c->locations());
        } finally {
            Http::assertSentCount(4);
        }
    }

    public function test_other_client_errors_are_not_retried(): void
    {
        $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/locations' => Http::response(['errors' => []], 400)]);

        $this->expectException(SquareRequestFailed::class);
        try {
            $this->withClient(fn (SquareClient $c) => $c->locations());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_refused_token_marks_the_connection_for_reconnecting(): void
    {
        $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/locations' => Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401)]);

        $this->inTenant($this->org, function () {
            try {
                SquareClient::forOrg()->locations();
                $this->fail('no exception');
            } catch (SquareAuthFailed) {
            }
        });

        $this->assertSame('needs_reauth', $this->squareConnection($this->org)->status);
        // Further calls stop before reaching Square.
        $this->expectException(SquareAuthFailed::class);
        $this->withClient(fn (SquareClient $c) => $c->locations());
    }

    public function test_a_token_with_under_a_week_left_is_refreshed_first(): void
    {
        $this->connectSquare($this->org, ['token_expires_at' => now()->addDays(3)]);
        Http::fake([
            self::SQUARE.'/oauth2/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2',
                'expires_at' => now()->addDays(30)->toIso8601ZuluString(), 'merchant_id' => 'MERCHANT1']),
            self::SQUARE.'/v2/locations' => Http::response(['locations' => []]),
        ]);

        $this->withClient(fn (SquareClient $c) => $c->locations());

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::SQUARE.'/oauth2/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-1');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/locations') && $r->hasHeader('Authorization', 'Bearer access-2'));
        $row = $this->squareConnection($this->org);
        $encryptor = app(EnvelopeEncryptor::class);
        $this->assertSame(['access-2', 'refresh-2'], [$encryptor->decrypt($this->org->id, $row->access_token_enc), $encryptor->decrypt($this->org->id, $row->refresh_token_enc)]);
        $this->assertTrue(CarbonImmutable::parse($row->token_expires_at)->gt(now()->addDays(29)));
    }

    public function test_a_refused_refresh_marks_the_connection_for_reconnecting(): void
    {
        $this->connectSquare($this->org, ['token_expires_at' => now()->addDays(1)]);
        Http::fake([self::SQUARE.'/oauth2/token' => Http::response(['errors' => [['code' => 'UNAUTHORIZED']]], 401)]);

        $this->inTenant($this->org, function () {
            try {
                SquareClient::forOrg()->locations();
                $this->fail('no exception');
            } catch (SquareAuthFailed) {
            }
        });

        $this->assertSame('needs_reauth', $this->squareConnection($this->org)->status);
        Http::assertSentCount(1);
    }

    public function test_catalog_pages(): void
    {
        $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/catalog/list*' => Http::sequence()
            ->push(['objects' => [['id' => 'C1']], 'cursor' => 'c2'])
            ->push(['objects' => [['id' => 'C2']]])]);

        $ids = $this->withClient(fn (SquareClient $c) => array_column(iterator_to_array($c->catalog(['CATEGORY']), false), 'id'));

        $this->assertSame(['C1', 'C2'], $ids);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'types=CATEGORY') && str_contains($r->url(), 'cursor=c2'));
    }
}
