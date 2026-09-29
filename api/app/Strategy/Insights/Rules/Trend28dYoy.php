<?php
// api/app/Strategy/Insights/Rules/Trend28dYoy.php
namespace App\Strategy\Insights\Rules;

/** 28 days against the same 28 days a year earlier (364 days: same weekdays). */
class Trend28dYoy extends Trend28d
{
    public const TYPE = 'trend_28d_yoy';

    protected function offsetDays(): int
    {
        return 364;
    }
}
