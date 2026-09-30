<?php
// api/app/Strategy/Insights/Rules/AnomalyDay.php
namespace App\Strategy\Insights\Rules;

use App\Strategy\Insights\Finding;
use App\Strategy\Insights\VenueHistory;

/**
 * A day in the lookback far from its same-weekday baseline: the median of the previous
 * baseline_weeks same weekdays (at least min_baseline_days present). Flagged when the change is at
 * least min_change_pct AND the robust z-score (0.6745 x deviation / MAD) is at least min_robust_z.
 * A perfectly steady baseline (MAD = 0) is judged on the percentage alone.
 */
class AnomalyDay extends Rule
{
    public const TYPE = 'anomaly_day';

    public function findings(VenueHistory $history): array
    {
        $found = [];
        for ($i = $this->setting('lookback_days') - 1; $i >= 0; $i--) {
            $day = $history->asOf->subDays($i);
            $value = $history->value($day);
            if ($value === null) {
                continue;
            }

            $baseline = [];
            for ($k = 1; $k <= $this->setting('baseline_weeks'); $k++) {
                $v = $history->value($day->subWeeks($k));
                if ($v !== null) {
                    $baseline[] = $v;
                }
            }
            if (count($baseline) < $this->setting('min_baseline_days')) {
                continue;
            }

            $median = VenueHistory::median($baseline);
            if ($median <= 0) {
                continue;
            }
            $pct = round(($value - $median) * 100 / $median, 1);
            $mad = VenueHistory::median(array_map(fn ($v) => abs($v - $median), $baseline));
            $z = $mad > 0 ? round(0.6745 * ($value - $median) / $mad, 1) : null;

            if (abs($pct) >= $this->setting('min_change_pct') && ($z === null || abs($z) >= $this->setting('min_robust_z'))) {
                $found[] = new Finding(self::TYPE, true, $day, $day, [
                    'value_cents' => $value,
                    'baseline_cents' => (int) round($median),
                    'change_pct' => $pct,
                    'robust_z' => $z,
                    'baseline_days' => count($baseline),
                ], $pct > 0 ? 'high' : 'low');
            }
        }

        return $found;
    }
}
