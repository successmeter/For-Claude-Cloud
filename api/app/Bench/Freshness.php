<?php
// api/app/Bench/Freshness.php
namespace App\Bench;

use App\Models\Venue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How current a venue's data is (Plan C design §5.3), in business days: the venue's current
 * business date is "now" in its time zone, less its cutoff (a trading day that ends at 04:00 is
 * still yesterday at 03:59). fresh: data up to yesterday or today; behind: 2-7 days old; stale:
 * older; none: no data.
 */
final class Freshness
{
    public static function for(Venue $venue, ?string $latestDate, CarbonInterface $now): array
    {
        if ($latestDate === null) {
            return ['status' => 'none', 'latest_date' => null, 'days_behind' => null];
        }

        [$h, $m] = array_map('intval', explode(':', (string) $venue->business_day_cutoff));
        $today = CarbonImmutable::instance($now)->setTimezone($venue->timezone)->subHours($h)->subMinutes($m)->startOfDay();
        $behind = (int) CarbonImmutable::parse($latestDate, $venue->timezone)->startOfDay()->diffInDays($today, false);

        return [
            'status' => $behind <= 1 ? 'fresh' : ($behind <= 7 ? 'behind' : 'stale'),
            'latest_date' => $latestDate,
            'days_behind' => $behind,
        ];
    }
}
