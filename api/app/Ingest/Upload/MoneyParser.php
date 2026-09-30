<?php
// api/app/Ingest/Upload/MoneyParser.php
namespace App\Ingest\Upload;

/**
 * Decimal dollars to integer cents without floats. Accepts "$", spaces and thousands commas and up
 * to two decimals ("1,234.50", "$0.5"). Refuses parentheses, other currencies, comma decimals and
 * more than 12 integer digits.
 */
final class MoneyParser
{
    public static function toCents(string $value): ?int
    {
        $v = str_replace([' ', ','], '', trim($value));
        $negative = str_starts_with($v, '-');
        $v = ltrim($negative ? substr($v, 1) : $v, '$');
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $v, $m)) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $negative ? -$cents : $cents;
    }
}
