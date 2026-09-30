<?php
// api/app/Ingest/Upload/DateFormats.php
namespace App\Ingest\Upload;

use Carbon\CarbonImmutable;

/**
 * The date formats an upload may use (Plan C design §4.2). Day-first only: US month-first dates
 * are not offered, and a file that could be either is flagged ambiguous by MappingDetector.
 */
final class DateFormats
{
    /** format name => [pattern, index of year, month, day in the match] */
    private const FORMATS = [
        'YYYY-MM-DD' => ['/^(\d{4})-(\d{2})-(\d{2})$/', 1, 2, 3],
        'DD/MM/YYYY' => ['/^(\d{2})\/(\d{2})\/(\d{4})$/', 3, 2, 1],
        'D/M/YYYY' => ['/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', 3, 2, 1],
        'DD-MM-YYYY' => ['/^(\d{2})-(\d{2})-(\d{4})$/', 3, 2, 1],
        'DD.MM.YYYY' => ['/^(\d{2})\.(\d{2})\.(\d{4})$/', 3, 2, 1],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::FORMATS);
    }

    public static function parse(string $format, string $value): ?CarbonImmutable
    {
        if (! isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException("Unknown date format {$format}.");
        }
        [$pattern, $y, $m, $d] = self::FORMATS[$format];
        if (! preg_match($pattern, trim($value), $match) || ! checkdate((int) $match[$m], (int) $match[$d], (int) $match[$y])) {
            return null;
        }

        return CarbonImmutable::create((int) $match[$y], (int) $match[$m], (int) $match[$d], 0, 0, 0, 'UTC');
    }

    /** @param list<string> $values @return list<string> formats that parse every non-blank value */
    public static function candidates(array $values): array
    {
        $values = array_values(array_filter(array_map('trim', $values), fn ($v) => $v !== ''));
        if ($values === []) {
            return [];
        }

        return array_values(array_filter(self::names(), function ($format) use ($values) {
            foreach ($values as $v) {
                if (self::parse($format, $v) === null) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** True when every value could also be read month-first (both leading fields <= 12). */
    public static function couldBeMonthFirst(string $format, array $values): bool
    {
        if ($format === 'YYYY-MM-DD') {
            return false;
        }
        foreach ($values as $v) {
            if (trim($v) === '') {
                continue;
            }
            [$first, $second] = array_map('intval', preg_split('/[\/.\-]/', trim($v)));
            if ($first > 12 || $second > 12) {
                return false;
            }
        }

        return true;
    }
}
