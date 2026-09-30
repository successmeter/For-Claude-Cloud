<?php
// api/app/Strategy/Insights/Rules/DataGap.php
namespace App\Strategy\Insights\Rules;

use App\Strategy\Insights\Finding;
use App\Strategy\Insights\VenueHistory;

/** Days without data in the lookback, from the venue's first day with data. Explains gaps elsewhere. */
class DataGap extends Rule
{
    public const TYPE = 'data_gap';

    public function findings(VenueHistory $history): array
    {
        $first = $history->firstDate();
        if ($first === null) {
            return [];
        }
        $from = $history->asOf->subDays($this->setting('lookback_days') - 1)->max($first);

        $missing = [];
        for ($d = $from; $d->lte($history->asOf); $d = $d->addDay()) {
            if ($history->value($d) === null) {
                $missing[] = $d->toDateString();
            }
        }
        if ($missing === []) {
            return [];
        }

        return [new Finding(self::TYPE, true, $from, $history->asOf, [
            'missing_days' => count($missing),
            'first_missing' => $missing[0],
            'last_missing' => end($missing),
        ])];
    }
}
