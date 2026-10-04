<?php
// api/app/Pos/Square/SquareSync.php
namespace App\Pos\Square;

use App\Bench\RecomputeVenue;
use App\Models\Venue;
use App\Pos\SplitDeriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One Square location's sales into its venue (Plan E design §3 Sync job). The first sync backfills
 * 24 months; later ones re-pull the last 7 complete business days (POS edits, late refunds). For the
 * window it replaces the per-category rows, writes each day's revenue and completed-order count
 * (revisions for changed days), derives food/drinks/other, then recomputes metrics. Covers are never
 * touched. Re-running a window gives the same rows. Runs inside the org's tenant context.
 */
class SquareSync
{
    public const BACKFILL_MONTHS = 24;

    public const REPULL_DAYS = 7;

    public function __construct(private SplitDeriver $splits, private RecomputeVenue $recompute) {}

    /** @return array{from: string, to: string, days: int} */
    public function run(SquareClient $client, object $link): array
    {
        $venue = Venue::findOrFail($link->venue_id);
        [$h, $m] = array_map('intval', explode(':', (string) $venue->business_day_cutoff));
        $cutoff = $h * 60 + $m;

        // The current business day is still trading: the window ends the day before.
        $today = CarbonImmutable::now($venue->timezone)->subMinutes($cutoff)->startOfDay();
        $to = $today->subDay();
        $backfill = $link->backfilled_at === null;
        $from = $backfill ? $today->subMonthsNoOverflow(self::BACKFILL_MONTHS) : $today->subDays(self::REPULL_DAYS);

        $totals = new DayTotals($venue->timezone, $cutoff, SquareCatalog::load($client));
        $start = CarbonImmutable::parse($from->toDateString(), $venue->timezone)->addMinutes($cutoff);
        $end = CarbonImmutable::parse($to->toDateString(), $venue->timezone)->addDay()->addMinutes($cutoff);
        // Month by month, so one search never spans years of orders.
        // Boundaries step from the start (not chunk to chunk), so month ends never drift.
        for ($i = 0, $chunk = $start; $chunk->lt($end); $chunk = $next) {
            $next = $start->addMonthsNoOverflow(++$i)->min($end);
            foreach ($client->completedOrders($link->location_id, $chunk, $next) as $order) {
                $totals->add($order);
            }
        }

        $categories = $totals->categories();
        // Days start at the venue's first Square sale, so earlier (uploaded) days are never zeroed.
        $known = DB::table('sales_daily_categories')->where('venue_id', $venue->id)->where('source', 'square')->min('business_date');
        $first = match (true) {
            $known !== null && ! $backfill => max($from->toDateString(), $known),
            default => array_key_first($categories),
        };
        $changed = $first === null ? null : $this->write($venue, $first, $to->toDateString(), $categories, $totals->orders());

        $now = now();
        DB::table('pos_location_links')->where('connection_id', $link->connection_id)->where('location_id', $link->location_id)
            ->update(['last_synced_at' => $now] + ($backfill ? ['backfilled_at' => $now] : []));
        DB::table('pos_connections')->where('id', $link->connection_id)
            ->update(['last_synced_at' => $now, 'consecutive_failures' => 0, 'last_error' => null, 'updated_at' => $now]);

        if ($changed !== null) {
            $this->recompute->run($venue->id, CarbonImmutable::parse($changed));
        }

        return ['from' => $first ?? $from->toDateString(), 'to' => $to->toDateString(), 'days' => count($categories)];
    }

    /** @return string|null the earliest day that changed */
    private function write(Venue $venue, string $from, string $to, array $categories, array $orders): ?string
    {
        DB::table('sales_daily_categories')->where('venue_id', $venue->id)->where('source', 'square')
            ->whereBetween('business_date', [$from, $to])->delete();
        $rows = [];
        foreach ($categories as $day => $cats) {
            foreach ($cats as $key => $c) {
                $rows[] = ['org_id' => $venue->org_id, 'venue_id' => $venue->id, 'business_date' => $day, 'source' => 'square',
                    'category_key' => $key, 'category_name' => $c['name'], 'net_cents' => $c['cents'], 'quantity' => round($c['qty'], 3)];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('sales_daily_categories')->insert($chunk);
        }

        $existing = DB::table('sales_daily')->where('venue_id', $venue->id)->whereBetween('business_date', [$from, $to])->get()->keyBy('business_date');
        $changed = [];
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $date = $d->toDateString();
            // A day with no orders while connected took nothing; refunds can't take revenue below zero.
            $revenue = max(0, array_sum(array_column($categories[$date] ?? [], 'cents')));
            $tx = $orders[$date] ?? 0;
            $old = $existing->get($date);

            if ($old === null) {
                DB::table('sales_daily')->insert([
                    'org_id' => $venue->org_id, 'venue_id' => $venue->id, 'business_date' => $date, 'revenue_cents' => $revenue,
                    'gst_inclusive' => true, 'tx_count' => $tx, 'source' => 'pos', 'created_at' => now(), 'revised_at' => now(),
                ]);
                $changed[] = $date;

                continue;
            }
            if ((int) $old->revenue_cents === $revenue && (int) $old->tx_count === $tx && $old->tx_count !== null
                && (bool) $old->gst_inclusive && $old->source === 'pos') {
                continue;
            }
            DB::table('sales_daily_revisions')->insert([
                'org_id' => $venue->org_id, 'venue_id' => $venue->id, 'business_date' => $date,
                'old_revenue_cents' => $old->revenue_cents, 'old_gst_inclusive' => $old->gst_inclusive, 'old_tx_count' => $old->tx_count,
                'old_food_cents' => $old->food_cents, 'old_drinks_cents' => $old->drinks_cents, 'old_other_cents' => $old->other_cents,
                'new_revenue_cents' => $revenue, 'new_gst_inclusive' => true, 'new_tx_count' => $tx, 'revised_at' => now(),
            ]);
            // The split is re-derived below; clearing it keeps food + drinks + other = revenue meanwhile.
            DB::table('sales_daily')->where('venue_id', $venue->id)->where('business_date', $date)->update([
                'revenue_cents' => $revenue, 'gst_inclusive' => true, 'tx_count' => $tx, 'source' => 'pos', 'ingestion_run_id' => null,
                'food_cents' => null, 'drinks_cents' => null, 'other_cents' => null,
                'revision' => DB::raw("nextval('sales_daily_revision_seq')"), 'revised_at' => now(),
            ]);
            $changed[] = $date;
        }

        $split = $this->splits->apply($venue->id, $from, $to);

        return collect([...$changed, $split])->filter()->min();
    }
}
