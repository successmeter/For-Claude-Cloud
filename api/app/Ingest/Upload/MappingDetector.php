<?php
// api/app/Ingest/Upload/MappingDetector.php
namespace App\Ingest\Upload;

use App\Ingest\Csv\CsvTable;

/**
 * Proposes which columns hold the date, revenue, transaction count, covers, food and drinks (Plan C
 * design §4.2, Plan E §4). Covers, food and drinks are found first so "Food sales" is never taken
 * for the total; food and drinks are proposed only as a pair. Header
 * names are matched exactly first, then as contained words, in priority order. The user always
 * confirms; a proposal is never applied on its own.
 */
class MappingDetector
{
    private const DATE = ['date', 'business date', 'trading date', 'day'];

    private const REVENUE = ['revenue', 'net sales', 'sales', 'takings', 'total', 'gross sales', 'amount'];

    private const TX = ['transactions', 'transaction count', 'tx', 'orders', 'receipts', 'count'];

    private const COVERS = ['covers', 'pax', 'guests', 'guest count', 'heads'];

    private const FOOD = ['food', 'kitchen'];

    private const DRINKS = ['drinks', 'beverage', 'beverages', 'bev', 'bar', 'liquor', 'alcohol'];

    public function propose(CsvTable $table, bool $gstDefault): array
    {
        $date = $this->match($table->header, self::DATE) ?? $this->dateByValues($table);
        $covers = $this->match($table->header, self::COVERS, exclude: [$date]);
        $food = $this->match($table->header, self::FOOD, exclude: [$date, $covers]);
        $drinks = $this->match($table->header, self::DRINKS, exclude: [$date, $covers, $food]);
        if ($food === null || $drinks === null) {
            $food = $drinks = null;
        }
        $revenue = $this->match($table->header, self::REVENUE, exclude: [$date, $covers, $food, $drinks]);
        $tx = $this->match($table->header, self::TX, exclude: [$date, $revenue, $covers, $food, $drinks]);

        $format = null;
        $ambiguous = false;
        if ($date !== null) {
            $values = array_column($table->rows, $table->column($date));
            $candidates = DateFormats::candidates($values);
            // Zero-padded day/month files match both slash formats; they mean the same thing.
            if (in_array('DD/MM/YYYY', $candidates, true)) {
                $candidates = array_values(array_diff($candidates, ['D/M/YYYY']));
            }
            if (count($candidates) === 1) {
                $ambiguous = DateFormats::couldBeMonthFirst($candidates[0], $values);
                $format = $ambiguous ? null : $candidates[0];
            }
        }

        return [
            'date_column' => $date,
            'date_format' => $format,
            'date_ambiguous' => $ambiguous,
            'revenue_column' => $revenue,
            'tx_count_column' => $tx,
            'gst_inclusive' => $revenue === null ? $gstDefault : $this->gst($revenue, $gstDefault),
            'covers_column' => $covers,
            'food_column' => $food,
            'drinks_column' => $drinks,
        ];
    }

    private function match(array $header, array $names, array $exclude = []): ?string
    {
        $candidates = array_values(array_filter($header, fn ($h) => ! in_array($h, $exclude, true)));
        foreach ([true, false] as $exact) {
            foreach ($names as $name) {
                foreach ($candidates as $h) {
                    $n = self::normalise($h);
                    if ($exact ? $n === $name : preg_match('/\b'.preg_quote($name, '/').'\b/', $n)) {
                        return $h;
                    }
                }
            }
        }

        return null;
    }

    private function dateByValues(CsvTable $table): ?string
    {
        foreach ($table->header as $i => $h) {
            if (DateFormats::candidates(array_column($table->rows, $i)) !== []) {
                return $h;
            }
        }

        return null;
    }

    private function gst(string $header, bool $default): bool
    {
        $n = self::normalise($header);
        if (preg_match('/\b(ex|excl|exc|excluding) ?gst\b|\bexcl\b/', $n)) {
            return false;
        }
        if (preg_match('/\b(inc|incl|including) ?gst\b|\bincl\b/', $n)) {
            return true;
        }

        return $default;
    }

    /** Lower case, punctuation to spaces, single spaces. */
    private static function normalise(string $header): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($header))));
    }
}
