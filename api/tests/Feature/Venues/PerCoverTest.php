<?php
// api/tests/Feature/Venues/PerCoverTest.php

namespace Tests\Feature\Venues;

use App\Bench\RecomputeVenue;
use App\Covers\CoversService;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan E Task 4: covers, food and drinks in the series, and the 28-day per-cover block (design §5). */
class PerCoverTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-31 09:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org, ['timezone' => 'Australia/Perth']);
        $this->viewer = $this->member($this->org, 'viewer');
    }

    /** @param array<string, array{0: int, 1: ?int, 2: ?int, 3?: bool}> $days date => [revenue, food, drinks, gst inclusive] */
    private function sales(array $days): void
    {
        $this->inTenantOf($this->venue, function () use ($days) {
            foreach ($days as $date => $d) {
                $split = $d[1] === null ? [null, null, null] : [$d[1], $d[2], $d[0] - $d[1] - $d[2]];
                DB::table('sales_daily')->insert([
                    'org_id' => $this->org->id, 'venue_id' => $this->venue->id, 'business_date' => $date, 'revenue_cents' => $d[0],
                    'gst_inclusive' => $d[3] ?? true, 'source' => 'upload', 'created_at' => now(), 'revised_at' => now(),
                    'food_cents' => $split[0], 'drinks_cents' => $split[1], 'other_cents' => $split[2],
                ]);
            }
            app(RecomputeVenue::class)->run($this->venue->id, CarbonImmutable::parse(min(array_keys($days))));
        });
    }

    private function covers(array $days): void
    {
        $this->inTenantOf($this->venue, fn () => app(CoversService::class)->save($this->venue, $days, 'manual', null));
    }

    private function overview()
    {
        return $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/overview")->assertOk();
    }

    public function test_per_cover_uses_only_days_with_covers_and_sales(): void
    {
        $this->sales([
            '2026-03-27' => [100000, 60000, 30000],
            '2026-03-28' => [200000, null, null],
            '2026-03-29' => [300000, 150000, 120000],
            '2026-03-30' => [50000, 20000, 20000],
        ]);
        // 03-30 has no covers; 03-26 has covers but no sales: both are left out, never counted as zero.
        $this->covers(['2026-03-26' => 10, '2026-03-27' => 50, '2026-03-28' => 80, '2026-03-29' => 120]);

        $response = $this->overview();

        $response->assertJsonPath('per_cover', [
            'from' => '2026-03-03', 'to' => '2026-03-30',
            'days_with_covers' => 3,
            'covers' => 250,
            'revenue_cents' => 600000,
            'revenue_per_cover_cents' => 2400,
            // Food and drinks per cover over the days with both covers and a split (03-27, 03-29: 170 covers).
            'days_with_split' => 2,
            'food_per_cover_cents' => 1235,
            'drinks_per_cover_cents' => 882,
        ]);
        // The mix covers every day with a split, covers or not (03-27, 03-29, 03-30).
        $response->assertJsonPath('mix', [
            'from' => '2026-03-03', 'to' => '2026-03-30', 'days_with_split' => 3,
            'food_cents' => 230000, 'drinks_cents' => 170000, 'other_cents' => 50000,
            'food_pct' => 51.1, 'drinks_pct' => 37.8, 'other_pct' => 11.1,
        ]);

        $series = collect($response->json('series'))->keyBy('date');
        $this->assertSame([50, 60000, 30000], [$series['2026-03-27']['covers'], $series['2026-03-27']['food_cents'], $series['2026-03-27']['drinks_cents']]);
        $this->assertSame([80, null, null], [$series['2026-03-28']['covers'], $series['2026-03-28']['food_cents'], $series['2026-03-28']['drinks_cents']]);
        $this->assertSame([null, 20000], [$series['2026-03-30']['covers'], $series['2026-03-30']['food_cents']]);
    }

    public function test_no_covers_and_no_split_means_null_blocks(): void
    {
        $this->sales(['2026-03-30' => [100000, null, null]]);

        $this->overview()->assertJsonPath('per_cover', null)->assertJsonPath('mix', null);
    }

    public function test_food_and_drinks_follow_the_gst_basis_of_the_day(): void
    {
        $this->sales(['2026-03-30' => [100000, 60000, 40000, false]]);
        $this->covers(['2026-03-30' => 100]);

        $this->overview()
            ->assertJsonPath('per_cover.revenue_per_cover_cents', 1100)
            ->assertJsonPath('per_cover.food_per_cover_cents', 660)
            ->assertJsonPath('mix.food_cents', 66000)
            ->assertJsonPath('series.89.food_cents', 66000);
    }

    public function test_the_metrics_endpoint_carries_covers_and_split_by_day(): void
    {
        $this->sales(['2026-03-30' => [100000, 60000, 40000]]);
        $this->covers(['2026-03-30' => 40]);

        $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/metrics?from=2026-03-30&to=2026-03-30")->assertOk()
            ->assertJsonPath('data.0.covers', 40)->assertJsonPath('data.0.food_cents', 60000)->assertJsonPath('data.0.drinks_cents', 40000);
    }
}
