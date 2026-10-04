<?php
// api/app/Bench/Rollups.php
namespace App\Bench;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Day, week and month views over daily_venue_metrics (Plan C design §3.8): rollups, not sources of
 * truth. Weeks are ISO weeks (Monday start); last year is the week 52 weeks back and the same
 * calendar month. Every period carries its coverage, and a change is given only when both periods
 * are complete, so a half-loaded month is never compared with a full one.
 *
 * Callers run inside tenant context (RLS limits every read to the org).
 */
class Rollups
{
    public function daily(string $venueId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->guard($from, $to);

        return array_map(fn ($r) => [
            'date' => $r->d,
            'revenue_cents' => self::int($r->revenue_cents),
            'last_week_cents' => self::int($r->same_weekday_last_week_cents),
            'last_year_cents' => self::int($r->same_weekday_last_year_cents),
            'wow_pct' => self::pct($r->revenue_cents, $r->same_weekday_last_week_cents),
            'yoy_pct' => self::pct($r->revenue_cents, $r->same_weekday_last_year_cents),
            // Transactions as uploaded (Plan D: average check per transaction); null when not supplied.
            'transactions' => self::int($r->tx_count),
            // Plan E: covers as entered or uploaded; food and drinks GST-inclusive, null without a split.
            'covers' => self::int($r->covers),
            'food_cents' => self::int($r->food_cents),
            'drinks_cents' => self::int($r->drinks_cents),
        ], DB::select(<<<SQL
            SELECT d::date::text AS d, m.revenue_cents, m.same_weekday_last_week_cents, m.same_weekday_last_year_cents, s.tx_count,
                   c.covers, {$this->incGst('s.food_cents')} AS food_cents, {$this->incGst('s.drinks_cents')} AS drinks_cents
            FROM generate_series(:from::date, :to::date, interval '1 day') AS d
            LEFT JOIN daily_venue_metrics m ON m.venue_id = :venue AND m.business_date = d::date
            LEFT JOIN sales_daily s ON s.venue_id = :venue2 AND s.business_date = d::date
            LEFT JOIN daily_covers c ON c.venue_id = :venue3 AND c.business_date = d::date
            ORDER BY d
        SQL, ['venue' => $venueId, 'venue2' => $venueId, 'venue3' => $venueId, 'from' => $from->toDateString(), 'to' => $to->toDateString()]));
    }

    /**
     * Over the 28 days ending $to (Plan E design §5): spend per cover over days with both sales and
     * covers, food and drinks per cover over those that also have a split, and the food/drinks mix
     * over every day with a split. A block is null when no day qualifies; GST-inclusive throughout.
     *
     * @return array{per_cover: ?array, mix: ?array}
     */
    public function perCover(string $venueId, CarbonImmutable $to): array
    {
        $from = $to->subDays(27);
        $r = DB::selectOne(<<<SQL
            SELECT count(c.covers) AS days_with_covers,
                   sum(c.covers) AS covers,
                   sum(s.revenue_inc_gst_cents) FILTER (WHERE c.covers IS NOT NULL) AS revenue,
                   count(*) FILTER (WHERE c.covers IS NOT NULL AND s.food_cents IS NOT NULL) AS days_with_split_covers,
                   sum(c.covers) FILTER (WHERE s.food_cents IS NOT NULL) AS split_covers,
                   sum({$this->incGst('s.food_cents')}) FILTER (WHERE c.covers IS NOT NULL) AS cover_food,
                   sum({$this->incGst('s.drinks_cents')}) FILTER (WHERE c.covers IS NOT NULL) AS cover_drinks,
                   count(s.food_cents) AS days_with_split,
                   sum({$this->incGst('s.food_cents')}) AS food,
                   sum({$this->incGst('s.drinks_cents')}) AS drinks,
                   sum({$this->incGst('s.other_cents')}) AS other
            FROM sales_daily s
            LEFT JOIN daily_covers c ON c.venue_id = s.venue_id AND c.business_date = s.business_date
            WHERE s.venue_id = :venue AND s.business_date BETWEEN :from AND :to
        SQL, ['venue' => $venueId, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        $range = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $per = fn ($cents, $covers) => $cents === null || (int) $covers === 0 ? null : (int) round((int) $cents / (int) $covers);
        $perCover = (int) $r->days_with_covers === 0 ? null : $range + [
            'days_with_covers' => (int) $r->days_with_covers,
            'covers' => (int) $r->covers,
            'revenue_cents' => (int) $r->revenue,
            'revenue_per_cover_cents' => $per($r->revenue, $r->covers),
            'days_with_split' => (int) $r->days_with_split_covers,
            'food_per_cover_cents' => $per($r->cover_food, $r->split_covers),
            'drinks_per_cover_cents' => $per($r->cover_drinks, $r->split_covers),
        ];
        $total = (int) $r->food + (int) $r->drinks + (int) $r->other;
        $pct = fn ($part) => $total === 0 ? null : round((int) $part * 100 / $total, 1);
        $mix = (int) $r->days_with_split === 0 ? null : $range + [
            'days_with_split' => (int) $r->days_with_split,
            'food_cents' => (int) $r->food,
            'drinks_cents' => (int) $r->drinks,
            'other_cents' => (int) $r->other,
            'food_pct' => $pct($r->food),
            'drinks_pct' => $pct($r->drinks),
            'other_pct' => $pct($r->other),
        ];

        return ['per_cover' => $perCover, 'mix' => $mix];
    }

    /** A sales_daily money column on the GST-inclusive basis, as revenue_inc_gst_cents is (decision C3). */
    private function incGst(string $column): string
    {
        return "CASE WHEN s.gst_inclusive THEN {$column} ELSE round({$column} * 1.1)::bigint END";
    }

    public function weekly(string $venueId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->guard($from, $to);

        return $this->periods($venueId, <<<'SQL'
            SELECT s::date AS start, (s + interval '6 days')::date AS finish,
                   (s - interval '364 days')::date AS ly_start, (s - interval '358 days')::date AS ly_finish,
                   to_char(s, 'IYYY-"W"IW') AS label
            FROM generate_series(date_trunc('week', :from::date), :to::date, interval '7 days') AS s
        SQL, $from, $to);
    }

    public function monthly(string $venueId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->guard($from, $to);

        return $this->periods($venueId, <<<'SQL'
            SELECT s::date AS start, (s + interval '1 month' - interval '1 day')::date AS finish,
                   (s - interval '1 year')::date AS ly_start, (s - interval '1 year' + interval '1 month' - interval '1 day')::date AS ly_finish,
                   to_char(s, 'YYYY-MM') AS label
            FROM generate_series(date_trunc('month', :from::date), :to::date, interval '1 month') AS s
        SQL, $from, $to);
    }

    private function periods(string $venueId, string $periodsSql, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::select(<<<SQL
            WITH p AS ({$periodsSql})
            SELECT p.start::text AS start, p.label,
                   (p.finish - p.start + 1) AS days, (p.ly_finish - p.ly_start + 1) AS ly_days,
                   cur.cents, cur.n, ly.cents AS ly_cents, ly.n AS ly_n
            FROM p
            CROSS JOIN LATERAL (
                SELECT sum(revenue_cents) AS cents, count(*) AS n FROM daily_venue_metrics
                WHERE venue_id = :venue AND business_date BETWEEN p.start AND p.finish
            ) cur
            CROSS JOIN LATERAL (
                SELECT sum(revenue_cents) AS cents, count(*) AS n FROM daily_venue_metrics
                WHERE venue_id = :venue AND business_date BETWEEN p.ly_start AND p.ly_finish
            ) ly
            ORDER BY p.start
        SQL, ['venue' => $venueId, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return array_map(function ($r) {
            $complete = (int) $r->n === (int) $r->days && (int) $r->ly_n === (int) $r->ly_days;

            return [
                'period' => $r->start,
                'label' => $r->label,
                'revenue_cents' => self::int($r->cents),
                'days_with_data' => (int) $r->n,
                'days_in_period' => (int) $r->days,
                'last_year_cents' => self::int($r->ly_cents),
                'last_year_days_with_data' => (int) $r->ly_n,
                'change_pct' => $complete ? self::pct($r->cents, $r->ly_cents) : null,
            ];
        }, $rows);
    }

    private function guard(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lt($from)) {
            throw new \InvalidArgumentException('The range ends before it starts.');
        }
        if ($to->gt($from->addYears(3))) {
            throw new \InvalidArgumentException('The range is longer than three years.');
        }
    }

    /** Percent change, one decimal; null when either side is missing or the base is zero. */
    public static function pct(mixed $value, mixed $base): ?float
    {
        if ($value === null || $base === null || (int) $base === 0) {
            return null;
        }

        return round(((int) $value - (int) $base) * 100 / (int) $base, 1);
    }

    private static function int(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
