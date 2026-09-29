<?php
// api/app/Strategy/Insights/Rules/Trend28dVsPrior.php
namespace App\Strategy\Insights\Rules;

/** 28 days against the 28 days before them. */
class Trend28dVsPrior extends Trend28d
{
    public const TYPE = 'trend_28d_vs_prior';

    protected function offsetDays(): int
    {
        return 28;
    }
}
