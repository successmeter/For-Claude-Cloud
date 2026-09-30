<?php
// api/app/Support/CsvWriter.php
namespace App\Support;

/**
 * Every CSV the API emits goes through here. A cell starting with =, +, -, @, tab or carriage
 * return gets a leading apostrophe so spreadsheets show it as text instead of running it as a
 * formula (CSV formula injection, 05 §5.7).
 */
final class CsvWriter
{
    /** @param list<string> $header @param list<list<string|int|null>> $rows */
    public static function write(array $header, array $rows): string
    {
        return implode('', array_map(fn ($row) => implode(',', array_map(self::cell(...), $row))."\n", [$header, ...$rows]));
    }

    private static function cell(string|int|null $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }
        if (strpbrk($value, ",\"\r\n\t") !== false) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
