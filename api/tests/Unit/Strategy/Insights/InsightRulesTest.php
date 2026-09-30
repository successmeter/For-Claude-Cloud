<?php
// api/tests/Unit/Strategy/Insights/InsightRulesTest.php

namespace Tests\Unit\Strategy\Insights;

use App\Strategy\Insights\Rules\AnomalyDay;
use App\Strategy\Insights\Rules\BestWorstWeekday;
use App\Strategy\Insights\Rules\DataGap;
use App\Strategy\Insights\Rules\Trend28dVsPrior;
use App\Strategy\Insights\Rules\Trend28dYoy;
use App\Strategy\Insights\Rules\WeeklyStreak;
use App\Strategy\Insights\VenueHistory;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Each rule just under and just over its threshold (Plan C design §6; thresholds as config/insights.php). */
class InsightRulesTest extends TestCase
{
    private const CONFIG = [
        'trend_28d_yoy' => ['material_pct' => 5.0],
        'trend_28d_vs_prior' => ['material_pct' => 10.0],
        'weekly_streak' => ['min_weeks' => 3],
        'anomaly_day' => ['lookback_days' => 28, 'baseline_weeks' => 8, 'min_baseline_days' => 4, 'min_change_pct' => 30.0, 'min_robust_z' => 3.5],
        'best_worst_weekday' => ['weeks' => 12, 'min_weeks' => 4, 'search_weeks' => 26],
        'data_gap' => ['lookback_days' => 28],
    ];

    private const AS_OF = '2026-06-28'; // a Sunday

    /** @param \Closure(CarbonImmutable): ?int $value */
    private function history(string $from, \Closure $value, string $asOf = self::AS_OF): VenueHistory
    {
        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($asOf)); $d = $d->addDay()) {
            $v = $value($d);
            if ($v !== null) {
                $days[$d->toDateString()] = $v;
            }
        }

        return new VenueHistory(CarbonImmutable::parse($asOf), $days);
    }

    private function apply(string $rule, VenueHistory $history): array
    {
        return array_map(fn ($f) => $f->toArray(), (new $rule(self::CONFIG))->findings($history));
    }

    private function asOf(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::AS_OF);
    }

    // --- trends -----------------------------------------------------------------------------

    public function test_28_day_yoy_trend_is_material_from_5_percent(): void
    {
        $recentStart = $this->asOf()->subDays(27);
        $for = fn (int $now) => $this->history('2025-06-01', fn (CarbonImmutable $d) => $d->gte($recentStart) ? $now : 100);

        $over = $this->apply(Trend28dYoy::class, $for(105));
        $this->assertCount(1, $over);
        $this->assertSame(['up', true, 2940, 2800, 5.0], [$over[0]['direction'], $over[0]['material'], $over[0]['figures']['value_cents'], $over[0]['figures']['comparison_cents'], $over[0]['figures']['change_pct']]);
        $this->assertSame(['from' => '2026-06-01', 'to' => '2026-06-28'], $over[0]['period']);
        $this->assertSame(['2025-06-02', '2025-06-29'], [$over[0]['figures']['comparison_from'], $over[0]['figures']['comparison_to']]);

        $under = $this->apply(Trend28dYoy::class, $for(104));
        $this->assertSame([false, 4.0], [$under[0]['material'], $under[0]['figures']['change_pct']]);

        $down = $this->apply(Trend28dYoy::class, $for(90));
        $this->assertSame(['down', true], [$down[0]['direction'], $down[0]['material']]);
    }

    public function test_trends_need_complete_windows(): void
    {
        $gapLastYear = $this->history('2025-06-01', fn (CarbonImmutable $d) => $d->toDateString() === '2025-06-10' ? null : 100);
        $this->assertSame([], $this->apply(Trend28dYoy::class, $gapLastYear));
        $this->assertSame([], $this->apply(Trend28dYoy::class, $this->history('2026-01-01', fn () => 100)));
    }

    public function test_28_day_trend_against_the_prior_28_days_is_material_from_10_percent(): void
    {
        $recentStart = $this->asOf()->subDays(27);
        $for = fn (int $now) => $this->history('2026-04-01', fn (CarbonImmutable $d) => $d->gte($recentStart) ? $now : 100);

        $over = $this->apply(Trend28dVsPrior::class, $for(110));
        $this->assertSame([true, 10.0, 3080, 2800], [$over[0]['material'], $over[0]['figures']['change_pct'], $over[0]['figures']['value_cents'], $over[0]['figures']['comparison_cents']]);
        $this->assertSame(['2026-05-04', '2026-05-31'], [$over[0]['figures']['comparison_from'], $over[0]['figures']['comparison_to']]);
        $under = $this->apply(Trend28dVsPrior::class, $for(109));
        $this->assertSame([false, 9.0, 'up'], [$under[0]['material'], $under[0]['figures']['change_pct'], $under[0]['direction']]);
        $this->assertSame('flat', $this->apply(Trend28dVsPrior::class, $for(100))[0]['direction']);
    }

    // --- weekly streak ----------------------------------------------------------------------

    /** Weeks ending on AS_OF (newest last) at $perDay each; earlier weeks at 950/day; last year 1000/day. */
    private function weeks(array $perDay): VenueHistory
    {
        $firstStreakWeek = $this->asOf()->startOfWeek()->subWeeks(count($perDay) - 1);

        return $this->history('2025-01-01', function (CarbonImmutable $d) use ($perDay, $firstStreakWeek) {
            if ($d->lt($this->asOf()->subDays(300))) {
                return 1000;
            }
            if ($d->lt($firstStreakWeek)) {
                return 950;
            }

            return $perDay[intdiv($firstStreakWeek->diffInDays($d), 7)];
        });
    }

    public function test_three_falling_weeks_below_last_year_are_a_streak(): void
    {
        $found = $this->apply(WeeklyStreak::class, $this->weeks([900, 850, 800]));

        $this->assertCount(1, $found);
        $this->assertSame(['down', 3, 5600, -20.0], [$found[0]['direction'], $found[0]['figures']['weeks'], $found[0]['figures']['latest_week_cents'], $found[0]['figures']['latest_week_yoy_pct']]);
        $this->assertSame(['from' => '2026-06-08', 'to' => '2026-06-28'], $found[0]['period']);
    }

    public function test_two_falling_weeks_are_not(): void
    {
        $this->assertSame([], $this->apply(WeeklyStreak::class, $this->weeks([950, 900, 850])));
    }

    public function test_rising_weeks_above_last_year(): void
    {
        $found = $this->apply(WeeklyStreak::class, $this->weeks([1010, 1020, 1030, 1040]));

        $this->assertSame(['up', 4], [$found[0]['direction'], $found[0]['figures']['weeks']]);
    }

    public function test_the_current_incomplete_week_is_ignored(): void
    {
        // As of a Wednesday: the streak is judged on weeks up to the Sunday before.
        $asOf = '2026-07-01';
        $history = $this->history('2025-01-01', fn (CarbonImmutable $d) => $d->gt(CarbonImmutable::parse('2026-06-28')) ? 10 : $this->weeks([900, 850, 800])->revenue[$d->toDateString()] ?? null, $asOf);

        $found = $this->apply(WeeklyStreak::class, $history);
        $this->assertSame(3, $found[0]['figures']['weeks']);
    }

    // --- anomalies --------------------------------------------------------------------------

    /** Same-weekday values follow $pattern by week; $target overrides one day. */
    private function anomalies(\Closure $pattern, string $date, ?int $value): array
    {
        return $this->apply(AnomalyDay::class, $this->history('2026-01-01', function (CarbonImmutable $d) use ($pattern, $date, $value) {
            return $d->toDateString() === $date ? $value : $pattern($d);
        }));
    }

    public function test_a_day_far_from_its_weekday_baseline_is_an_anomaly(): void
    {
        $steady = fn (CarbonImmutable $d) => 1000 + ((intdiv($d->dayOfYear, 7) % 3) - 1) * 10; // 990 / 1000 / 1010

        $high = $this->anomalies($steady, '2026-06-20', 1310);
        $this->assertCount(1, $high);
        $this->assertSame(['high', 1310, 1000, 31.0, 8], [$high[0]['direction'], $high[0]['figures']['value_cents'], $high[0]['figures']['baseline_cents'], $high[0]['figures']['change_pct'], $high[0]['figures']['baseline_days']]);
        $this->assertGreaterThan(3.5, $high[0]['figures']['robust_z']);
        $this->assertSame(['from' => '2026-06-20', 'to' => '2026-06-20'], $high[0]['period']);

        $this->assertSame([], $this->anomalies($steady, '2026-06-20', 1290));
        $this->assertSame('low', $this->anomalies($steady, '2026-06-20', 690)[0]['direction']);
    }

    public function test_a_noisy_weekday_needs_a_large_robust_z_too(): void
    {
        // Alternating 600 / 1400: median 1000, MAD 400. 1400 is 40% up but z = 0.67.
        $noisy = fn (CarbonImmutable $d) => intdiv($d->dayOfYear, 7) % 2 === 0 ? 600 : 1400;

        $this->assertSame([], $this->anomalies($noisy, '2026-06-20', 1400));
        $this->assertCount(1, $this->anomalies($noisy, '2026-06-20', 4000)); // z = 5.1
    }

    public function test_a_flat_baseline_uses_the_percentage_alone(): void
    {
        $found = $this->anomalies(fn () => 1000, '2026-06-20', 1300);

        $this->assertSame([30.0, null], [$found[0]['figures']['change_pct'], $found[0]['figures']['robust_z']]);
        $this->assertSame([], $this->anomalies(fn () => 1000, '2026-06-20', 1299));
    }

    public function test_too_little_baseline_is_not_judged(): void
    {
        $found = $this->apply(AnomalyDay::class, $this->history('2026-05-25', fn (CarbonImmutable $d) => $d->toDateString() === '2026-06-20' ? 5000 : 1000));

        $this->assertSame([], $found); // only 3 earlier Saturdays
    }

    public function test_only_the_last_28_days_are_checked(): void
    {
        $this->assertSame([], $this->anomalies(fn () => 1000, '2026-05-31', 5000));
    }

    // --- weekdays ---------------------------------------------------------------------------

    public function test_best_and_worst_weekday(): void
    {
        $found = $this->apply(BestWorstWeekday::class, $this->history('2026-03-01', fn (CarbonImmutable $d) => match ($d->dayOfWeekIso) {
            6 => 3000, 1 => 500, default => 1000,
        }));

        $this->assertSame([
            'best_weekday' => 'saturday', 'best_median_cents' => 3000, 'best_share_pct' => 35.3,
            'worst_weekday' => 'monday', 'worst_median_cents' => 500, 'worst_share_pct' => 5.9, 'weeks' => 12,
        ], $found[0]['figures']);
        $this->assertFalse($found[0]['material']);
        $this->assertSame(['from' => '2026-04-06', 'to' => '2026-06-28'], $found[0]['period']);
    }

    public function test_weekdays_need_four_complete_weeks(): void
    {
        $this->assertSame([], $this->apply(BestWorstWeekday::class, $this->history('2026-06-02', fn () => 1000)));
        $this->assertCount(1, $this->apply(BestWorstWeekday::class, $this->history('2026-06-01', fn () => 1000)));
    }

    // --- gaps -------------------------------------------------------------------------------

    public function test_missing_days_in_the_last_28(): void
    {
        $missing = ['2026-06-03', '2026-06-04', '2026-06-20'];
        $found = $this->apply(DataGap::class, $this->history('2026-01-01', fn (CarbonImmutable $d) => in_array($d->toDateString(), $missing, true) ? null : 1000));

        $this->assertSame(['missing_days' => 3, 'first_missing' => '2026-06-03', 'last_missing' => '2026-06-20'], $found[0]['figures']);
        $this->assertTrue($found[0]['material']);
    }

    public function test_days_before_the_first_upload_are_not_gaps(): void
    {
        $this->assertSame([], $this->apply(DataGap::class, $this->history('2026-06-20', fn () => 1000)));
        $this->assertSame([], $this->apply(DataGap::class, $this->history('2026-01-01', fn () => 1000)));
    }
}
