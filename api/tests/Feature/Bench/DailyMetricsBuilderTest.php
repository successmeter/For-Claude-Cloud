<?php
// api/tests/Feature/Bench/DailyMetricsBuilderTest.php

namespace Tests\Feature\Bench;

use App\Bench\DailyMetricsBuilder;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class DailyMetricsBuilderTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venue = $this->venue($this->org());
    }

    private function build(string $from = '2000-01-01'): void
    {
        $this->inTenantOf($this->venue, fn () => app(DailyMetricsBuilder::class)->rebuild($this->venue->id, CarbonImmutable::parse($from)));
    }

    public function test_constant_series(): void
    {
        $this->putSales($this->venue, $this->series('2025-01-01', '2026-03-31', fn () => 100));
        $this->build();

        $m = $this->metricsOn($this->venue, '2026-03-31');
        $this->assertSame(
            [100, 100, 100, 700, 700, 700, 2800, 2800, 2800, '100.00'],
            [(int) $m->revenue_cents, (int) $m->same_weekday_last_week_cents, (int) $m->same_weekday_last_year_cents,
                (int) $m->rolling_7_cents, (int) $m->rolling_7_prev_cents, (int) $m->rolling_7_ly_cents,
                (int) $m->rolling_28_cents, (int) $m->rolling_28_prev_cents, (int) $m->rolling_28_ly_cents, $m->growth_index],
        );

        // The first day has no history: every comparison is null, not zero.
        $first = $this->metricsOn($this->venue, '2025-01-01');
        $this->assertNull($first->same_weekday_last_week_cents);
        $this->assertNull($first->rolling_7_cents);
        $this->assertNull($first->growth_index);
        // Day 7 completes the first 7-day window.
        $this->assertSame(700, (int) $this->metricsOn($this->venue, '2025-01-07')->rolling_7_cents);
        $this->assertNull($this->metricsOn($this->venue, '2025-01-06')->rolling_7_cents);
    }

    public function test_a_missing_day_nulls_exactly_the_windows_that_cover_it(): void
    {
        $days = $this->series('2026-01-01', '2026-03-31', fn () => 100);
        unset($days['2026-03-01']);
        $this->putSales($this->venue, $days);
        $this->build();

        $this->assertNull($this->metricsOn($this->venue, '2026-03-01'));
        $this->assertSame(700, (int) $this->metricsOn($this->venue, '2026-02-28')->rolling_7_cents);
        foreach (['2026-03-02', '2026-03-07'] as $covered) {
            $this->assertNull($this->metricsOn($this->venue, $covered)->rolling_7_cents, $covered);
        }
        $this->assertSame(700, (int) $this->metricsOn($this->venue, '2026-03-08')->rolling_7_cents);
        $this->assertNull($this->metricsOn($this->venue, '2026-03-28')->rolling_28_cents);
        $this->assertSame(2800, (int) $this->metricsOn($this->venue, '2026-03-29')->rolling_28_cents);
        // Its week-later neighbour loses only the week-on-week figure.
        $this->assertNull($this->metricsOn($this->venue, '2026-03-08')->same_weekday_last_week_cents);
    }

    public function test_zero_is_a_real_day(): void
    {
        // Closed on Mondays: uploads 0, which counts as present.
        $this->putSales($this->venue, $this->series('2026-01-05', '2026-02-01', fn (CarbonImmutable $d) => $d->isMonday() ? 0 : 100));
        $this->build();

        $this->assertSame(600, (int) $this->metricsOn($this->venue, '2026-01-11')->rolling_7_cents);
        $this->assertSame(2400, (int) $this->metricsOn($this->venue, '2026-02-01')->rolling_28_cents);
    }

    public function test_year_on_year_keeps_the_weekday_across_a_leap_day(): void
    {
        $this->putSales($this->venue, $this->series('2027-01-01', '2028-03-31', fn (CarbonImmutable $d) => (int) $d->format('Ymd') % 100000));
        $this->build();

        $m = $this->metricsOn($this->venue, '2028-03-01');
        $lastYear = CarbonImmutable::parse('2028-03-01')->subDays(364);
        $this->assertSame('2027-03-03', $lastYear->toDateString());
        $this->assertSame($lastYear->dayOfWeek, CarbonImmutable::parse('2028-03-01')->dayOfWeek);
        $this->assertSame((int) $lastYear->format('Ymd') % 100000, (int) $m->same_weekday_last_year_cents);
    }

    public function test_ex_gst_days_enter_metrics_with_gst(): void
    {
        $this->putSales($this->venue, ['2026-03-01' => 1000], gstInclusive: false);
        $this->build();

        $this->assertSame(1100, (int) $this->metricsOn($this->venue, '2026-03-01')->revenue_cents);
    }

    public function test_a_partial_rebuild_after_an_edit_matches_a_full_rebuild(): void
    {
        $this->putSales($this->venue, $this->series('2024-01-01', '2026-06-30', fn (CarbonImmutable $d) => 1000 + $d->dayOfYear));
        $this->build();

        $this->putSales($this->venue, ['2025-02-10' => 99999]);
        $this->build('2025-02-10');
        $partial = $this->snapshot();

        $this->inTenantOf($this->venue, fn () => DB::table('daily_venue_metrics')->update(['revenue_cents' => -1, 'rolling_28_ly_cents' => null]));
        $this->build();

        $this->assertEquals($this->snapshot(), $partial);
        // A day's edit reaches the 28-day last-year window for 364..391 days: 2026-03-08 is the last row it changes.
        $value = fn (CarbonImmutable $d) => $d->toDateString() === '2025-02-10' ? 99999 : 1000 + $d->dayOfYear;
        $windowSum = fn (string $end) => array_sum($this->series(
            CarbonImmutable::parse($end)->subDays(27)->toDateString(), $end, $value));
        $this->assertSame($windowSum('2025-03-09'), (int) $this->metricsOn($this->venue, '2026-03-08')->rolling_28_ly_cents);
        $this->assertSame($windowSum('2025-03-10'), (int) $this->metricsOn($this->venue, '2026-03-09')->rolling_28_ly_cents);
        $this->assertGreaterThan(99999, $windowSum('2025-03-09'));
        $this->assertLessThan(99999, $windowSum('2025-03-10'));
    }

    public function test_rows_before_the_rebuild_date_are_left_alone(): void
    {
        $this->putSales($this->venue, $this->series('2026-01-01', '2026-01-31', fn () => 100));
        $this->build();
        $this->inTenantOf($this->venue, fn () => DB::table('daily_venue_metrics')->where('business_date', '<', '2026-01-20')->update(['revenue_cents' => 7]));

        $this->build('2026-01-20');

        $this->assertSame(7, (int) $this->metricsOn($this->venue, '2026-01-19')->revenue_cents);
        $this->assertSame(100, (int) $this->metricsOn($this->venue, '2026-01-20')->revenue_cents);
    }

    public function test_it_needs_tenant_context(): void
    {
        $this->expectException(\LogicException::class);
        app(DailyMetricsBuilder::class)->rebuild($this->venue->id, CarbonImmutable::parse('2026-01-01'));
    }

    private function snapshot(): array
    {
        return $this->inTenantOf($this->venue, fn () => DB::table('daily_venue_metrics')
            ->orderBy('business_date')->get()->map(fn ($r) => collect($r)->except('updated_at')->all())->all());
    }
}
