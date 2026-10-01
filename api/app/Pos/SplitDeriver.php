<?php
// api/app/Pos/SplitDeriver.php
namespace App\Pos;

use Illuminate\Support\Facades\DB;

/**
 * Food, drinks and other for a venue's POS days, from its per-category totals and the owner's mapping
 * (Plan E design §2.2-2.3). Unmapped categories, service charges and ad hoc items count as Other.
 * A day whose food or drinks come out negative or above the day's revenue (refunds outweighing
 * sales in a category) gets no split rather than a wrong one. Changed days are kept in
 * sales_daily_revisions. Runs inside tenant context; the caller recomputes metrics.
 */
class SplitDeriver
{
    /** @return string|null the earliest day whose split changed */
    public function apply(string $venueId, ?string $from = null, ?string $to = null): ?string
    {
        $bind = ['venue' => $venueId, 'venue2' => $venueId, 'from' => $from ?? '1900-01-01', 'to' => $to ?? '9999-12-31'];
        $target = <<<'SQL'
            WITH k AS (
                SELECT c.business_date AS d,
                       coalesce(sum(c.net_cents) FILTER (WHERE m.kind = 'food'), 0) AS food,
                       coalesce(sum(c.net_cents) FILTER (WHERE m.kind = 'drinks'), 0) AS drinks
                FROM sales_daily_categories c
                LEFT JOIN category_mappings m ON m.venue_id = c.venue_id AND m.source = c.source AND m.category_key = c.category_key
                WHERE c.venue_id = :venue AND c.source = 'square' AND c.business_date BETWEEN :from AND :to
                GROUP BY c.business_date
            ),
            t AS (
                SELECT s.business_date AS d, s.food_cents AS old_food, s.drinks_cents AS old_drinks, s.other_cents AS old_other,
                       s.org_id, s.revenue_cents, s.gst_inclusive, s.tx_count,
                       CASE WHEN ok THEN k.food END AS new_food,
                       CASE WHEN ok THEN k.drinks END AS new_drinks,
                       CASE WHEN ok THEN s.revenue_cents - k.food - k.drinks END AS new_other
                FROM sales_daily s
                JOIN k ON k.d = s.business_date
                CROSS JOIN LATERAL (SELECT k.food >= 0 AND k.drinks >= 0 AND k.food + k.drinks <= s.revenue_cents AS ok) v
                WHERE s.venue_id = :venue2 AND s.source = 'pos'
            ),
            changed AS (
                SELECT * FROM t WHERE (old_food, old_drinks, old_other) IS DISTINCT FROM (new_food, new_drinks, new_other)
            )
        SQL;

        $earliest = DB::selectOne("{$target} SELECT min(d)::text AS d FROM changed", $bind)->d;
        if ($earliest === null) {
            return null;
        }

        DB::statement(<<<SQL
            {$target}
            INSERT INTO sales_daily_revisions (org_id, venue_id, business_date, old_revenue_cents, old_gst_inclusive, old_tx_count,
                old_food_cents, old_drinks_cents, old_other_cents,
                new_revenue_cents, new_gst_inclusive, new_tx_count, new_food_cents, new_drinks_cents, new_other_cents, revised_at)
            SELECT org_id, :venue3, d, revenue_cents, gst_inclusive, tx_count, old_food, old_drinks, old_other,
                   revenue_cents, gst_inclusive, tx_count, new_food, new_drinks, new_other, now()
            FROM changed
        SQL, $bind + ['venue3' => $venueId]);
        DB::statement(<<<SQL
            {$target}
            UPDATE sales_daily s SET food_cents = c.new_food, drinks_cents = c.new_drinks, other_cents = c.new_other,
                revision = nextval('sales_daily_revision_seq'), revised_at = now()
            FROM changed c WHERE s.venue_id = :venue3 AND s.business_date = c.d
        SQL, $bind + ['venue3' => $venueId]);

        return $earliest;
    }
}
