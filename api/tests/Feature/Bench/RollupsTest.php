<?php
// api/tests/Feature/Bench/RollupsTest.php

namespace Tests\Feature\Bench;

use App\Bench\DailyMetricsBuilder;
use App\Bench\Rollups;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class RollupsTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venue = $this->venue($this->org());
    }

    private function load(array $days): void
    {
        $this->putSales($this->venue, $days);
        $this->inTenantOf($this->venue, fn () => app(DailyMetricsBuilder::class)->rebuild($this->venue->id, CarbonImmutable::parse('2000-01-01')));
    }

    private function rollups(string $grain, string $from, string $to): array
    {
        return $this->inTenantOf($this->venue, fn () => app(Rollups::class)->{$grain}($this->venue->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
    }

    public function test_complete_weeks_compare_with_the_same_week_last_year(): void
    {
        $this->load($this->series('2025-01-01', '2026-03-31', fn (CarbonImmutable $d) => $d->year === 2026 ? 110 : 100));

        $weeks = $this->rollups('weekly', '2026-03-09', '2026-03-15');

        $this->assertCount(1, $weeks);
        $this->assertSame([
            'period' => '2026-03-09', 'label' => '2026-W11', 'revenue_cents' => 770, 'days_with_data' => 7, 'days_in_period' => 7,
            'last_year_cents' => 700, 'last_year_days_with_data' => 7, 'change_pct' => 10.0,
        ], $weeks[0]);
    }

    public function test_a_partial_week_reports_its_coverage_and_no_change(): void
    {
        $this->load($this->series('2025-01-01', '2026-03-11', fn () => 100)); // data stops on a Wednesday

        $week = $this->rollups('weekly', '2026-03-09', '2026-03-15')[0];

        $this->assertSame([300, 3, 7, 700, null], [$week['revenue_cents'], $week['days_with_data'], $week['days_in_period'], $week['last_year_cents'], $week['change_pct']]);
    }

    public function test_weeks_cover_the_range_from_the_monday_before_it(): void
    {
        $this->load($this->series('2026-03-01', '2026-03-31', fn () => 100));

        $weeks = $this->rollups('weekly', '2026-03-11', '2026-03-24');

        $this->assertSame(['2026-03-09', '2026-03-16', '2026-03-23'], array_column($weeks, 'period'));
    }

    public function test_week_53(): void
    {
        $this->load($this->series('2026-12-28', '2027-01-03', fn () => 100));

        $this->assertSame('2026-W53', $this->rollups('weekly', '2026-12-28', '2027-01-03')[0]['label']);
    }

    public function test_complete_month_against_the_same_month_last_year(): void
    {
        $this->load($this->series('2025-02-01', '2026-02-28', fn (CarbonImmutable $d) => $d->year === 2026 ? 120 : 100));

        $feb = $this->rollups('monthly', '2026-02-01', '2026-02-28')[0];

        $this->assertSame([
            'period' => '2026-02-01', 'label' => '2026-02', 'revenue_cents' => 3360, 'days_with_data' => 28, 'days_in_period' => 28,
            'last_year_cents' => 2800, 'last_year_days_with_data' => 28, 'change_pct' => 20.0,
        ], $feb);
    }

    public function test_leap_february_is_complete_only_with_29_days(): void
    {
        $this->load($this->series('2028-02-01', '2028-02-28', fn () => 100));

        $feb = $this->rollups('monthly', '2028-02-01', '2028-02-29')[0];

        $this->assertSame([28, 29, null], [$feb['days_with_data'], $feb['days_in_period'], $feb['change_pct']]);
    }

    public function test_a_period_without_data_is_null_not_zero(): void
    {
        $this->load(['2026-01-15' => 100]);

        $months = $this->rollups('monthly', '2025-12-01', '2026-01-31');

        $this->assertSame([null, 0], [$months[0]['revenue_cents'], $months[0]['days_with_data']]);
        $this->assertSame([100, 1], [$months[1]['revenue_cents'], $months[1]['days_with_data']]);
    }

    public function test_daily_rows_include_missing_days_as_null(): void
    {
        $this->load($this->series('2026-03-01', '2026-03-15', fn () => 100) + ['2026-03-17' => 50]);

        $days = $this->rollups('daily', '2026-03-15', '2026-03-17');

        $this->assertSame(['2026-03-15', '2026-03-16', '2026-03-17'], array_column($days, 'date'));
        $this->assertSame([100, null, 50], array_column($days, 'revenue_cents'));
        $this->assertSame([0.0, null, -50.0], array_column($days, 'wow_pct'));
    }

    public function test_ranges_are_limited_to_three_years(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rollups('daily', '2020-01-01', '2023-01-02');
    }

    public function test_the_range_must_not_run_backwards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rollups('weekly', '2026-02-01', '2026-01-01');
    }
}
