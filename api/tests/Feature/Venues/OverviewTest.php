<?php
// api/tests/Feature/Venues/OverviewTest.php

namespace Tests\Feature\Venues;

use App\Bench\Freshness;
use App\Bench\RecomputeVenue;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->viewer = $this->member($this->org, 'viewer');
    }

    private function load(array $days): void
    {
        $this->putSales($this->venue, $days);
        $this->inTenantOf($this->venue, fn () => app(RecomputeVenue::class)->run($this->venue->id, CarbonImmutable::parse('2000-01-01')));
    }

    public function test_overview_numbers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 09:00', 'Australia/Perth'));
        // Last year 100/day; this year 110/day except the last week at 121.
        $this->load($this->series('2025-01-01', '2026-03-30', function (CarbonImmutable $d) {
            return match (true) {
                $d->year === 2025 => 10000,
                $d->gte(CarbonImmutable::parse('2026-03-24')) => 12100,
                default => 11000,
            };
        }));

        $response = $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/overview")->assertOk();

        $response->assertJson([
            'currency' => 'AUD',
            'latest' => ['date' => '2026-03-30', 'revenue_cents' => 12100, 'last_week_cents' => 11000, 'last_year_cents' => 10000, 'wow_pct' => 10.0, 'yoy_pct' => 21.0],
            'totals' => [
                'rolling_7' => ['from' => '2026-03-24', 'to' => '2026-03-30', 'cents' => 84700, 'previous_cents' => 77000, 'last_year_cents' => 70000, 'vs_previous_pct' => 10.0, 'yoy_pct' => 21.0],
                'rolling_28' => ['from' => '2026-03-03', 'to' => '2026-03-30', 'cents' => 315700, 'previous_cents' => 308000, 'last_year_cents' => 280000, 'vs_previous_pct' => 2.5, 'yoy_pct' => 12.8],
            ],
            'freshness' => ['status' => 'fresh', 'latest_date' => '2026-03-30', 'days_behind' => 1],
        ]);
        $this->assertCount(90, $response->json('series'));
        $this->assertSame(['date' => '2026-03-30', 'revenue_cents' => 12100, 'last_year_cents' => 10000],
            array_intersect_key($response->json('series.89'), array_flip(['date', 'revenue_cents', 'last_year_cents'])));
        $this->assertSame('2025-12-31', $response->json('series.0.date')); // 90 days ending 2026-03-30
    }

    public function test_anomalies_come_from_the_latest_insights(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 09:00', 'Australia/Perth'));
        $this->load($this->series('2026-01-01', '2026-03-30', fn (CarbonImmutable $d) => $d->toDateString() === '2026-03-21' ? 50000 : 10000));

        $anomalies = $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/overview")->json('anomalies');

        $this->assertCount(1, $anomalies);
        $this->assertSame(['anomaly_day', 'high', '2026-03-21'], [$anomalies[0]['type'], $anomalies[0]['direction'], $anomalies[0]['period']['from']]);
    }

    public function test_an_empty_venue(): void
    {
        $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/overview")
            ->assertOk()
            ->assertExactJson([
                'venue_id' => $this->venue->id, 'currency' => 'AUD', 'latest' => null, 'totals' => null, 'per_cover' => null, 'mix' => null,
                'series' => [], 'anomalies' => [], 'freshness' => ['status' => 'none', 'latest_date' => null, 'days_behind' => null],
            ]);
    }

    public function test_another_orgs_venue_is_not_found(): void
    {
        $other = $this->venue($this->org('Other'));

        $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$other->id}/overview")->assertNotFound();
    }

    // --- freshness --------------------------------------------------------------------------

    private function freshness(string $timezone, string $cutoff, ?string $latest, string $nowUtc): array
    {
        $venue = new Venue(['timezone' => $timezone, 'business_day_cutoff' => $cutoff]);

        return Freshness::for($venue, $latest, CarbonImmutable::parse($nowUtc, 'UTC'));
    }

    public function test_the_business_day_turns_at_the_cutoff(): void
    {
        // 03:59 in Perth on the 20th is still the 19th's trading day.
        $this->assertSame(['status' => 'fresh', 'latest_date' => '2026-03-18', 'days_behind' => 1],
            $this->freshness('Australia/Perth', '04:00:00', '2026-03-18', '2026-03-19 19:59:00'));
        $this->assertSame(['status' => 'behind', 'latest_date' => '2026-03-18', 'days_behind' => 2],
            $this->freshness('Australia/Perth', '04:00:00', '2026-03-18', '2026-03-19 20:01:00'));
    }

    public function test_the_venues_time_zone_decides_the_day(): void
    {
        // 17:30 UTC: 04:30 on the 20th in Sydney (AEDT), 01:30 on the 20th in Perth.
        $this->assertSame('behind', $this->freshness('Australia/Sydney', '04:00:00', '2026-03-18', '2026-03-19 17:30:00')['status']);
        $this->assertSame('fresh', $this->freshness('Australia/Perth', '04:00:00', '2026-03-18', '2026-03-19 17:30:00')['status']);
    }

    public function test_stale_after_a_week(): void
    {
        $behind = $this->freshness('Australia/Perth', '04:00:00', '2026-03-13', '2026-03-20 04:00:00');
        $this->assertSame(['behind', 7], [$behind['status'], $behind['days_behind']]);
        $this->assertSame('stale', $this->freshness('Australia/Perth', '04:00:00', '2026-03-12', '2026-03-20 04:00:00')['status']);
        $this->assertSame('none', $this->freshness('Australia/Perth', '04:00:00', null, '2026-03-20 04:00:00')['status']);
    }
}
