<?php
// api/app/Strategy/Insights/Rules/WeeklyStreak.php
namespace App\Strategy\Insights\Rules;

use App\Strategy\Insights\Finding;
use App\Strategy\Insights\VenueHistory;

/**
 * Consecutive complete ISO weeks, ending with the latest one, each below (or above) both the week
 * before it and the same week last year (52 weeks back). The current, unfinished week is ignored.
 */
class WeeklyStreak extends Rule
{
    public const TYPE = 'weekly_streak';

    private const SEARCH_WEEKS = 26;

    public function findings(VenueHistory $history): array
    {
        $end = $history->lastWeekEnd();
        $week = fn (int $k) => $history->windowSum($end->subWeeks($k), 7);
        $lastYear = fn (int $k) => $history->windowSum($end->subWeeks($k)->subDays(364), 7);

        foreach (['down' => fn ($a, $b) => $a < $b, 'up' => fn ($a, $b) => $a > $b] as $direction => $beyond) {
            $n = 0;
            while ($n < self::SEARCH_WEEKS) {
                [$this_, $before, $ly] = [$week($n), $week($n + 1), $lastYear($n)];
                if ($this_ === null || $before === null || $ly === null || ! $beyond($this_, $before) || ! $beyond($this_, $ly)) {
                    break;
                }
                $n++;
            }
            if ($n >= $this->setting('min_weeks')) {
                return [new Finding(self::TYPE, true, $end->subWeeks($n - 1)->subDays(6), $end, [
                    'weeks' => $n,
                    'latest_week_cents' => $week(0),
                    'latest_week_yoy_pct' => VenueHistory::pct($week(0), $lastYear(0)),
                ], $direction)];
            }
        }

        return [];
    }
}
