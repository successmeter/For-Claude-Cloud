<?php
// api/app/Strategy/Insights/Rules/BestWorstWeekday.php
namespace App\Strategy\Insights\Rules;

use App\Strategy\Insights\Finding;
use App\Strategy\Insights\VenueHistory;

/**
 * Over the latest `weeks` complete ISO weeks (all seven days present; searched within
 * `search_weeks`), the weekday with the highest and the lowest median revenue and its share of
 * the week. Informational, never material. Ties go to the earlier weekday.
 */
class BestWorstWeekday extends Rule
{
    public const TYPE = 'best_worst_weekday';

    private const NAMES = [1 => 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function findings(VenueHistory $history): array
    {
        $end = $history->lastWeekEnd();
        $byWeekday = array_fill(1, 7, []);
        $weeks = 0;
        $earliest = null;
        for ($k = 0; $k < $this->setting('search_weeks') && $weeks < $this->setting('weeks'); $k++) {
            $sunday = $end->subWeeks($k);
            if ($history->windowSum($sunday, 7) === null) {
                continue;
            }
            for ($d = 1; $d <= 7; $d++) {
                $byWeekday[$d][] = $history->value($sunday->subDays(7 - $d));
            }
            $weeks++;
            $earliest = $sunday->subDays(6);
        }
        if ($weeks < $this->setting('min_weeks')) {
            return [];
        }

        $medians = array_map(fn ($values) => VenueHistory::median($values), $byWeekday);
        $total = array_sum($medians);
        $best = array_search(max($medians), $medians, true);
        $worst = array_search(min($medians), $medians, true);
        $share = fn (int $d) => $total > 0 ? round($medians[$d] * 100 / $total, 1) : null;

        return [new Finding(self::TYPE, false, $earliest, $end, [
            'best_weekday' => self::NAMES[$best],
            'best_median_cents' => (int) round($medians[$best]),
            'best_share_pct' => $share($best),
            'worst_weekday' => self::NAMES[$worst],
            'worst_median_cents' => (int) round($medians[$worst]),
            'worst_share_pct' => $share($worst),
            'weeks' => $weeks,
        ])];
    }
}
