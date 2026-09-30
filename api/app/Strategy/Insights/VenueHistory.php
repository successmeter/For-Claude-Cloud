<?php
// api/app/Strategy/Insights/VenueHistory.php
namespace App\Strategy\Insights;

use Carbon\CarbonImmutable;

/**
 * A venue's daily GST-inclusive revenue up to $asOf, as the insight rules see it. A date absent
 * from $revenue has no data; it is never read as zero.
 */
final class VenueHistory
{
    /** @param array<string, int> $revenue business date (Y-m-d) => cents */
    public function __construct(public readonly CarbonImmutable $asOf, public readonly array $revenue) {}

    public function value(CarbonImmutable $day): ?int
    {
        return $this->revenue[$day->toDateString()] ?? null;
    }

    /** Sum of the $days days ending on $end, or null if any of them has no data. */
    public function windowSum(CarbonImmutable $end, int $days): ?int
    {
        $sum = 0;
        for ($i = 0; $i < $days; $i++) {
            $v = $this->value($end->subDays($i));
            if ($v === null) {
                return null;
            }
            $sum += $v;
        }

        return $sum;
    }

    public function firstDate(): ?CarbonImmutable
    {
        return $this->revenue === [] ? null : CarbonImmutable::parse(min(array_keys($this->revenue)));
    }

    /** The Sunday ending the latest complete ISO week on or before $asOf. */
    public function lastWeekEnd(): CarbonImmutable
    {
        return $this->asOf->isSunday() ? $this->asOf : $this->asOf->previous(CarbonImmutable::SUNDAY);
    }

    public static function pct(int $value, int $base): ?float
    {
        return $base === 0 ? null : round(($value - $base) * 100 / $base, 1);
    }

    /** @param list<int|float> $values */
    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
