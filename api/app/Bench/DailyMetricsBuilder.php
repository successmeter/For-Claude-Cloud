<?php
// api/app/Bench/DailyMetricsBuilder.php
namespace App\Bench;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds daily_venue_metrics for one venue from $from to its last day with sales (Plan C design
 * §3.7). A day's change reaches rows up to 391 days later (the 28-day window a year on), so callers
 * pass the earliest changed date and everything after it is recomputed.
 *
 * Windows use RANGE frames over dates, so a window only has a value when every day in it is
 * present (count = length): a missing day is "no data", never zero. Last year is 364 days back,
 * the same weekday 52 weeks earlier.
 */
class DailyMetricsBuilder
{
    public function rebuild(string $venueId, CarbonImmutable $from): void
    {
        if (TenantContext::current() === null) {
            throw new \LogicException('DailyMetricsBuilder runs inside tenant context.');
        }

        DB::statement(<<<'SQL'
            WITH sales AS (
                SELECT org_id, business_date AS d, revenue_inc_gst_cents AS r
                FROM sales_daily WHERE venue_id = :venue
            ),
            windows AS (
                SELECT org_id, d, r,
                       CASE WHEN count(*) OVER w7 = 7 THEN sum(r) OVER w7 END AS r7,
                       CASE WHEN count(*) OVER w28 = 28 THEN sum(r) OVER w28 END AS r28
                FROM sales
                WINDOW w7 AS (ORDER BY d RANGE BETWEEN INTERVAL '6 days' PRECEDING AND CURRENT ROW),
                       w28 AS (ORDER BY d RANGE BETWEEN INTERVAL '27 days' PRECEDING AND CURRENT ROW)
            )
            INSERT INTO daily_venue_metrics (
                org_id, venue_id, business_date, revenue_cents,
                same_weekday_last_week_cents, same_weekday_last_year_cents,
                rolling_7_cents, rolling_7_prev_cents, rolling_7_ly_cents,
                rolling_28_cents, rolling_28_prev_cents, rolling_28_ly_cents,
                growth_index, updated_at
            )
            SELECT cur.org_id, :venue, cur.d, cur.r,
                   lw.r, ly.r,
                   cur.r7, lw.r7, ly.r7,
                   cur.r28, p28.r28, ly.r28,
                   CASE WHEN ly.r28 > 0 THEN round(100.0 * cur.r28 / ly.r28, 2) END,
                   now()
            FROM windows cur
            LEFT JOIN windows lw ON lw.d = cur.d - 7
            LEFT JOIN windows p28 ON p28.d = cur.d - 28
            LEFT JOIN windows ly ON ly.d = cur.d - 364
            WHERE cur.d >= :from
            ON CONFLICT (venue_id, business_date) DO UPDATE SET
                revenue_cents = EXCLUDED.revenue_cents,
                same_weekday_last_week_cents = EXCLUDED.same_weekday_last_week_cents,
                same_weekday_last_year_cents = EXCLUDED.same_weekday_last_year_cents,
                rolling_7_cents = EXCLUDED.rolling_7_cents,
                rolling_7_prev_cents = EXCLUDED.rolling_7_prev_cents,
                rolling_7_ly_cents = EXCLUDED.rolling_7_ly_cents,
                rolling_28_cents = EXCLUDED.rolling_28_cents,
                rolling_28_prev_cents = EXCLUDED.rolling_28_prev_cents,
                rolling_28_ly_cents = EXCLUDED.rolling_28_ly_cents,
                growth_index = EXCLUDED.growth_index,
                updated_at = EXCLUDED.updated_at
        SQL, ['venue' => $venueId, 'from' => $from->toDateString()]);
    }
}
