<?php
// api/app/Strategy/Insights/Rules/Trend28d.php
namespace App\Strategy\Insights\Rules;

use App\Strategy\Insights\Finding;
use App\Strategy\Insights\VenueHistory;

/** The 28 days ending on as_of against an earlier 28 days; both windows complete. */
abstract class Trend28d extends Rule
{
    /** Days between the two windows' ends. Both are whole weeks, so weekdays line up. */
    abstract protected function offsetDays(): int;

    public function findings(VenueHistory $history): array
    {
        $end = $history->asOf;
        $compareEnd = $end->subDays($this->offsetDays());
        $value = $history->windowSum($end, 28);
        $comparison = $history->windowSum($compareEnd, 28);
        if ($value === null || $comparison === null || $comparison === 0) {
            return [];
        }

        $pct = VenueHistory::pct($value, $comparison);

        return [new Finding(static::TYPE, abs($pct) >= $this->setting('material_pct'), $end->subDays(27), $end, [
            'value_cents' => $value,
            'comparison_cents' => $comparison,
            'change_pct' => $pct,
            'comparison_from' => $compareEnd->subDays(27)->toDateString(),
            'comparison_to' => $compareEnd->toDateString(),
        ], self::direction($pct))];
    }
}
