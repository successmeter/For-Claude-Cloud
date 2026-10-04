<?php
// api/tests/Feature/Pos/SquareSyncTest.php

namespace Tests\Feature\Pos;

use App\Covers\CoversService;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Pos\Square\SyncSquareLocation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\Support\SquareFixtures;
use Tests\TestCase;

/** Plan E Task 8: Square orders -> sales by day, by category (design §3, E4). */
class SquareSyncTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests, SquareFixtures;

    private Org $org;

    private Venue $venue;

    private User $owner;

    private string $connection;

    /** @var list<array> orders Square holds for location L1 */
    private array $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSquare();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->owner = $this->member($this->org);
        $this->owner->forceFill(['mfa_enabled' => true])->save();
        $this->venue = $this->venue($this->org, ['timezone' => 'Australia/Perth', 'business_day_cutoff' => '04:00']);
        $this->connection = $this->connectSquare($this->org);
        DB::table('pos_orgs')->insert(['org_id' => $this->org->id]);
        $this->linkLocation($this->org, $this->connection, 'L1', $this->venue->id);

        $this->orders = [
            // 03-29, evening: wine x2, a discounted steak (legacy category id), an ad hoc amount, a service charge and a tip.
            $this->order('2026-03-29T20:00:00+08:00', [['V_WINE', 3000, '2'], ['V_STEAK', 5000, '1'], [null, 500, '1']],
                ['service_charges' => [['total_money' => ['amount' => 1000, 'currency' => 'AUD']]], 'total_tip_money' => ['amount' => 800, 'currency' => 'AUD']]),
            // 02:30 on the 30th is still the 29th's trading day (cutoff 04:00).
            $this->order('2026-03-30T02:30:00+08:00', [['V_WINE', 1500, '1']]),
            $this->order('2026-03-30T12:00:00+08:00', [['V_STEAK', 4000, '1']]),
            // A return of one wine on the 30th: no sale lines, so not an order for the count.
            $this->order('2026-03-30T13:00:00+08:00', [], ['returns' => [['return_line_items' => [
                ['catalog_object_id' => 'V_WINE', 'quantity' => '1', 'total_money' => ['amount' => 1500, 'currency' => 'AUD']]]]]]),
            // Today is still trading: not synced yet.
            $this->order('2026-03-31T10:00:00+08:00', [['V_STEAK', 9900, '1']]),
        ];
        Http::preventStrayRequests();
        Http::fake([
            self::SQUARE.'/v2/catalog/list*' => Http::response(['objects' => [
                ['type' => 'CATEGORY', 'id' => 'C_WINE', 'category_data' => ['name' => 'Wine']],
                ['type' => 'CATEGORY', 'id' => 'C_MAINS', 'category_data' => ['name' => 'Mains']],
                ['type' => 'ITEM', 'id' => 'I_WINE', 'item_data' => ['name' => 'Shiraz', 'reporting_category' => ['id' => 'C_WINE'], 'variations' => [['id' => 'V_WINE']]]],
                ['type' => 'ITEM', 'id' => 'I_STEAK', 'item_data' => ['name' => 'Steak', 'category_id' => 'C_MAINS', 'variations' => [['id' => 'V_STEAK']]]],
            ]]),
            // Square filters by closed_at, as the real search does.
            self::SQUARE.'/v2/orders/search' => function (HttpRequest $r) {
                $range = $r['query']['filter']['date_time_filter']['closed_at'];
                $in = array_values(array_filter($this->orders, fn ($o) => CarbonImmutable::parse($o['closed_at'])->gte(CarbonImmutable::parse($range['start_at']))
                    && CarbonImmutable::parse($o['closed_at'])->lt(CarbonImmutable::parse($range['end_at']))));

                return Http::response(['orders' => $in]);
            },
        ]);
    }

    private function order(string $closedAt, array $lines, array $extra = []): array
    {
        return $extra + [
            'id' => 'O'.count($this->orders ?? []).md5($closedAt), 'state' => 'COMPLETED', 'closed_at' => $closedAt,
            'line_items' => array_map(fn ($l) => array_filter(['catalog_object_id' => $l[0], 'quantity' => $l[2],
                'total_money' => ['amount' => $l[1], 'currency' => 'AUD']], fn ($v) => $v !== null), $lines),
        ];
    }

    private function sync(): void
    {
        SyncSquareLocation::dispatchSync($this->org->id, 'L1');
    }

    private function sales(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('sales_daily')->orderBy('business_date')->get()->mapWithKeys(fn ($r) => [
            $r->business_date => [(int) $r->revenue_cents, $r->tx_count === null ? null : (int) $r->tx_count, $r->source,
                $r->food_cents === null ? null : (int) $r->food_cents, $r->drinks_cents === null ? null : (int) $r->drinks_cents],
        ])->all());
    }

    private function categories(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('sales_daily_categories')->orderBy('business_date')->orderBy('category_key')->get()
            ->map(fn ($r) => [$r->business_date, $r->category_key, $r->category_name, (int) $r->net_cents, (float) $r->quantity])->all());
    }

    public function test_the_first_sync_backfills_day_totals_by_category(): void
    {
        $this->sync();

        $this->assertSame([
            ['2026-03-29', 'C_MAINS', 'Mains', 5000, 1.0],
            ['2026-03-29', 'C_WINE', 'Wine', 4500, 3.0],
            ['2026-03-29', 'service_charges', 'Service charges', 1000, 0.0],
            ['2026-03-29', 'uncategorised', 'Uncategorised', 500, 1.0],
            ['2026-03-30', 'C_MAINS', 'Mains', 4000, 1.0],
            ['2026-03-30', 'C_WINE', 'Wine', -1500, -1.0],
        ], $this->categories());
        // Revenue is net of the return, without the tip; days start at the first Square sale.
        $this->assertSame(['2026-03-29' => [11000, 2, 'pos', null, null], '2026-03-30' => [2500, 1, 'pos', null, null]], $this->sales());
        $this->assertNotNull($this->metricsOn($this->venue, '2026-03-30'));

        $search = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/v2/orders/search'));
        $this->assertSame('2024-03-30T20:00:00Z', $search->first()['query']['filter']['date_time_filter']['closed_at']['start_at'], '24 months, from the cutoff');
        $this->assertSame('2026-03-30T20:00:00Z', $search->last()['query']['filter']['date_time_filter']['closed_at']['end_at'], 'up to the end of yesterday\'s trading day');
        $this->assertCount(24, $search, 'month by month');

        $link = $this->inTenant($this->org, fn () => DB::table('pos_location_links')->first());
        $this->assertNotNull($link->backfilled_at);
        $this->assertNotNull($this->squareConnection($this->org)->last_synced_at);
    }

    public function test_the_mapping_splits_synced_days(): void
    {
        $this->inTenant($this->org, fn () => DB::table('category_mappings')->insert([
            ['org_id' => $this->org->id, 'venue_id' => $this->venue->id, 'source' => 'square', 'category_key' => 'C_WINE', 'kind' => 'drinks', 'mapped_at' => now()],
            ['org_id' => $this->org->id, 'venue_id' => $this->venue->id, 'source' => 'square', 'category_key' => 'C_MAINS', 'kind' => 'food', 'mapped_at' => now()],
        ]));

        $this->sync();

        $this->assertSame([11000, 2, 'pos', 5000, 4500], $this->sales()['2026-03-29']);
        $this->assertSame([2500, 1, 'pos', null, null], $this->sales()['2026-03-30'], 'more wine returned than sold: no split');
    }

    public function test_resyncing_is_idempotent_and_repulls_the_last_week(): void
    {
        $this->sync();
        $revisions = fn () => $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->count());
        $before = [$this->categories(), $this->sales(), $revisions()];
        $sent = count(Http::recorded());

        $this->sync();

        $this->assertSame($before, [$this->categories(), $this->sales(), $revisions()]);
        $search = collect(Http::recorded())->slice($sent)->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/v2/orders/search'))->values();
        $this->assertCount(1, $search);
        $this->assertSame('2026-03-23T20:00:00Z', $search[0]['query']['filter']['date_time_filter']['closed_at']['start_at']);

        // A late refund on an already-synced day is picked up, with history.
        $this->orders[] = $this->order('2026-03-30T22:00:00+08:00', [], ['returns' => [['return_line_items' => [
            ['catalog_object_id' => 'V_STEAK', 'quantity' => '1', 'total_money' => ['amount' => 1000, 'currency' => 'AUD']]]]]]);
        $this->sync();
        $this->assertSame([1500, 1, 'pos', null, null], $this->sales()['2026-03-30']);
        $this->assertSame($before[2] + 1, $revisions());
    }

    public function test_uploaded_days_are_replaced_and_covers_are_left_alone(): void
    {
        $this->putSales($this->venue, ['2026-03-20' => 7000, '2026-03-29' => 9999]);
        $this->inTenant($this->org, fn () => app(CoversService::class)->save($this->venue, ['2026-03-29' => 60], 'manual', null));

        $this->sync();

        $sales = $this->sales();
        $this->assertSame([7000, null, 'upload'], array_slice($sales['2026-03-20'], 0, 3), 'before the first Square sale: untouched');
        $this->assertSame([11000, 2, 'pos'], array_slice($sales['2026-03-29'], 0, 3));
        $revision = $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->sole());
        $this->assertSame([9999, 11000], [(int) $revision->old_revenue_cents, (int) $revision->new_revenue_cents]);
        $this->assertSame(60, (int) $this->inTenant($this->org, fn () => DB::table('daily_covers')->value('covers')));
    }

    public function test_a_refused_token_needs_the_owner_and_writes_nothing(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 401)]);

        $this->sync();

        $this->assertSame('needs_reauth', $this->squareConnection($this->org)->status);
        $this->assertSame([], $this->sales());
    }

    public function test_five_failures_in_a_row_pause_the_connection(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 503)]);

        foreach (range(1, 4) as $i) {
            $this->sync();
        }
        $connection = $this->squareConnection($this->org);
        $this->assertSame([4, null, 'Square was not answering.'], [(int) $connection->consecutive_failures, $connection->paused_at, $connection->last_error]);

        $this->sync();
        $this->assertNotNull($this->squareConnection($this->org)->paused_at);
        $this->spa($this->owner, $this->org, 'GET', '/api/pos')->assertJsonPath('square.paused', true)->assertJsonPath('square.last_error', 'Square was not answering.');

        // Paused: the nightly run skips it.
        $sent = count(Http::recorded());
        $this->sync();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_the_nightly_command_queues_healthy_connections_only(): void
    {
        Queue::fake();
        $other = $this->org('Paused');
        $venue = $this->venue($other);
        $paused = $this->connectSquare($other, ['paused_at' => now()]);
        $this->linkLocation($other, $paused, 'L9', $venue->id);
        DB::table('pos_orgs')->insert(['org_id' => $other->id]);

        $this->artisan('pos:sync')->assertSuccessful();

        Queue::assertPushed(SyncSquareLocation::class, 1);
        Queue::assertPushed(SyncSquareLocation::class, fn ($job) => $job->orgId() === $this->org->id);
    }

    public function test_sync_now_resumes_and_is_rate_limited(): void
    {
        Queue::fake();
        $this->inTenant($this->org, fn () => DB::table('pos_connections')->update(['paused_at' => now(), 'consecutive_failures' => 5]));
        $manager = $this->member($this->org, 'manager');

        $this->spa($this->member($this->org, 'viewer'), $this->org, 'POST', '/api/pos/square/sync')->assertForbidden();
        $this->spa($manager, $this->org, 'POST', '/api/pos/square/sync')->assertStatus(202)->assertExactJson(['queued' => 1]);
        $this->assertNull($this->squareConnection($this->org)->paused_at);
        Queue::assertPushed(SyncSquareLocation::class, 1);

        $this->spa($manager, $this->org, 'POST', '/api/pos/square/sync')->assertStatus(429)->assertJsonPath('type', 'https://hub/problems/sync_too_soon');
        $this->travel(11)->minutes();
        $this->spa($this->owner, $this->org, 'POST', '/api/pos/square/sync')->assertStatus(202);
    }

    public function test_a_square_venue_uploads_covers_only(): void
    {
        $upload = fn (string $csv, array $mapping) => $this->actingAs($this->owner)->post("/api/venues/{$this->venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('f.csv', $csv), 'date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'gst_inclusive' => '1'] + $mapping,
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json']);

        $upload("date,revenue\n2026-03-01,100\n", ['revenue_column' => 'revenue'])
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/venue_uses_pos');
        $upload("date,covers\n2026-03-01,40\n", ['covers_column' => 'covers'])->assertCreated();
    }
}
