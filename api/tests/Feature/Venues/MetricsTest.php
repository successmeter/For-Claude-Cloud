<?php
// api/tests/Feature/Venues/MetricsTest.php

namespace Tests\Feature\Venues;

use App\Bench\RecomputeVenue;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-04-01 09:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->viewer = $this->member($this->org, 'viewer');
        $this->putSales($this->venue, $this->series('2025-01-01', '2026-03-31', fn () => 1000));
        $this->inTenantOf($this->venue, fn () => app(RecomputeVenue::class)->run($this->venue->id, CarbonImmutable::parse('2000-01-01')));
    }

    private function metrics(string $query = '')
    {
        return $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/metrics{$query}");
    }

    public function test_days_default_to_the_90_ending_on_the_latest_day(): void
    {
        $response = $this->metrics('?grain=day')->assertOk();

        $response->assertJson(['venue_id' => $this->venue->id, 'grain' => 'day', 'from' => '2026-01-01', 'to' => '2026-03-31', 'currency' => 'AUD']);
        $this->assertCount(90, $response->json('data'));
        $this->assertSame(['date' => '2026-03-31', 'revenue_cents' => 1000, 'last_week_cents' => 1000, 'last_year_cents' => 1000, 'wow_pct' => 0.0, 'yoy_pct' => 0.0],
            $response->json('data.89'));
    }

    public function test_weeks_and_months(): void
    {
        $weeks = $this->metrics('?grain=week')->assertOk();
        $this->assertCount(26, $weeks->json('data'));
        $this->assertSame(7000, $weeks->json('data.24.revenue_cents'));
        // Data ends on Tuesday 2026-03-31: the newest week is partial and not compared.
        $this->assertSame([2000, 2, null], [$weeks->json('data.25.revenue_cents'), $weeks->json('data.25.days_with_data'), $weeks->json('data.25.change_pct')]);

        $months = $this->metrics('?grain=month&from=2026-01-01&to=2026-03-31')->assertOk();
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($months->json('data'), 'label'));
        $this->assertSame([31000, 0.0], [$months->json('data.0.revenue_cents'), $months->json('data.0.change_pct')]);
        $this->assertCount(12, $this->metrics('?grain=month')->json('data'));
    }

    public function test_grain_defaults_to_day(): void
    {
        $this->metrics()->assertOk()->assertJsonPath('grain', 'day');
    }

    public function test_bad_requests_are_problems(): void
    {
        foreach (['?grain=hour', '?from=2026-13-01', '?from=2026-03-01&to=2026-02-01'] as $query) {
            $this->metrics($query)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
        }
        $this->metrics('?from=2020-01-01&to=2026-01-01')->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/range_too_long');
    }

    public function test_a_venue_without_data_ends_on_its_current_business_day(): void
    {
        $empty = $this->venue($this->org, ['name' => 'New']);

        $response = $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$empty->id}/metrics?grain=day")->assertOk();

        $this->assertSame('2026-04-01', $response->json('to'));
        $this->assertSame([null], array_unique(array_column($response->json('data'), 'revenue_cents')));
    }

    public function test_another_orgs_venue_is_not_found(): void
    {
        $other = $this->venue($this->org('Other'));

        $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$other->id}/metrics")->assertNotFound();
    }
}
