<?php
// api/app/Ingest/Upload/UploadPreviewService.php
namespace App\Ingest\Upload;

use App\Ingest\Models\IngestionRun;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validates every row of a mapped upload, compares it with the venue's sales and stages the valid
 * rows (Plan C design §4.3). Problems are codes with row numbers; cell content is never echoed.
 * Runs inside the request's tenant transaction.
 */
class UploadPreviewService
{
    public const MAX_PROBLEMS = 200;

    private const MAX_CENTS = 1_000_000_000; // $10,000,000 in a day: a cents/dollars or column mix-up

    private const EARLIEST = '2015-01-01';

    private const MAX_COVERS = 100_000;

    public function preview(Venue $venue, ReceivedFile $file, Mapping $mapping, User $user): IngestionRun
    {
        $today = CarbonImmutable::now($venue->timezone)->toDateString();
        [$rows, $problems] = $this->validate($file, $mapping, $today);

        $existing = DB::table('sales_daily')->where('venue_id', $venue->id)
            ->whereIn('business_date', array_keys($rows))
            ->get(['business_date', 'revenue_cents', 'gst_inclusive', 'tx_count', 'food_cents', 'drinks_cents'])->keyBy('business_date');
        $existingCovers = DB::table('daily_covers')->where('venue_id', $venue->id)
            ->whereIn('business_date', array_keys($rows))->pluck('covers', 'business_date');
        $int = fn ($v) => $v === null ? null : (int) $v;
        $counts = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'covers' => 0];
        foreach ($rows as $date => &$row) {
            $old = $existing->get($date);
            $row['change'] = match (true) {
                $row['revenue_cents'] === null => null,
                $old === null => 'new',
                (int) $old->revenue_cents === $row['revenue_cents'] && (bool) $old->gst_inclusive === $row['gst_inclusive']
                    && $int($old->tx_count) === $row['tx_count']
                    && $int($old->food_cents) === $row['food_cents'] && $int($old->drinks_cents) === $row['drinks_cents'] => 'unchanged',
                default => 'changed',
            };
            if ($row['change'] !== null) {
                $counts[$row['change']]++;
            }
            $row['covers_changed'] = $row['covers'] !== null && $int($existingCovers->get($date)) !== $row['covers'];
            $counts['covers'] += $row['covers_changed'] ? 1 : 0;
        }
        unset($row);

        $dates = array_keys($rows);
        sort($dates);
        $run = IngestionRun::create([
            'org_id' => $venue->org_id,
            'venue_id' => $venue->id,
            'method' => 'upload',
            'status' => 'previewed',
            'created_by' => $user->id,
            'file_sha256' => $file->sha256,
            'file_bytes' => $file->bytes,
            'row_count' => count($file->table->rows),
            'mapping' => $mapping->toArray(),
            'summary' => [
                'rows' => count($file->table->rows),
                ...$counts,
                'problems' => count($problems),
                'problems_truncated' => count($problems) > self::MAX_PROBLEMS,
                'first_date' => $dates[0] ?? null,
                'last_date' => $dates === [] ? null : end($dates),
            ],
            'problems' => array_slice($problems, 0, self::MAX_PROBLEMS),
            'basis_revision' => (int) DB::table('sales_daily')->where('venue_id', $venue->id)->max('revision'),
            'expires_at' => now()->addHours(config('ingest.run_hours')),
        ]);

        DB::table('ingest_orgs')->insertOrIgnore(['org_id' => $venue->org_id]);

        foreach (array_chunk($rows, 500, true) as $chunk) {
            DB::table('ingestion_run_rows')->insert(array_map(fn ($date, $r) => [
                'run_id' => $run->id, 'org_id' => $venue->org_id, 'business_date' => $date,
                'revenue_cents' => $r['revenue_cents'], 'gst_inclusive' => $r['gst_inclusive'],
                'tx_count' => $r['tx_count'], 'change' => $r['change'],
                'food_cents' => $r['food_cents'], 'drinks_cents' => $r['drinks_cents'], 'other_cents' => $r['other_cents'],
                'covers' => $r['covers'], 'covers_changed' => $r['covers_changed'],
            ], array_keys($chunk), $chunk));
        }

        return $run->refresh();
    }

    /** @return array{0: array<string, array>, 1: list<array>} valid rows by date, and problems */
    private function validate(ReceivedFile $file, Mapping $mapping, string $today): array
    {
        $rows = [];
        $problems = [];
        $problem = function (int $row, string $column, string $code) use (&$problems) {
            $problems[] = ['row' => $row, 'column' => $column, 'code' => $code];
        };

        foreach ($file->table->rows as $i => $cells) {
            $number = $file->table->rowNumbers[$i];

            $date = DateFormats::parse($mapping->dateFormat, $cells[$mapping->dateIndex]);
            $dateCode = match (true) {
                $date === null => 'date_unparseable',
                $date->toDateString() > $today => 'date_in_future',
                $date->toDateString() < self::EARLIEST => 'date_too_old',
                isset($rows[$date->toDateString()]) => 'date_duplicate',
                default => null,
            };
            if ($dateCode !== null) {
                $problem($number, $mapping->dateColumn, $dateCode);

                continue;
            }

            $cents = null;
            if ($mapping->revenueIndex !== null) {
                $cents = MoneyParser::toCents($cells[$mapping->revenueIndex]);
                $revenueCode = match (true) {
                    $cents === null => 'revenue_unparseable',
                    $cents < 0 => 'revenue_negative',
                    $cents > self::MAX_CENTS => 'revenue_too_large',
                    default => null,
                };
                if ($revenueCode !== null) {
                    $problem($number, $mapping->revenueColumn, $revenueCode);

                    continue;
                }
            }

            // Food and drinks: both blank means the day has no split; one blank counts as zero.
            $food = $drinks = $other = null;
            if ($mapping->foodIndex !== null) {
                $rawFood = trim($cells[$mapping->foodIndex]);
                $rawDrinks = trim($cells[$mapping->drinksIndex]);
                if ($rawFood !== '' || $rawDrinks !== '') {
                    $food = $rawFood === '' ? 0 : MoneyParser::toCents($rawFood);
                    $drinks = $rawDrinks === '' ? 0 : MoneyParser::toCents($rawDrinks);
                    if ($food === null || $food < 0) {
                        $problem($number, $mapping->foodColumn, 'food_invalid');

                        continue;
                    }
                    if ($drinks === null || $drinks < 0) {
                        $problem($number, $mapping->drinksColumn, 'drinks_invalid');

                        continue;
                    }
                    if ($food + $drinks > $cents) {
                        $problem($number, $mapping->foodColumn, 'split_exceeds_total');

                        continue;
                    }
                    $other = $cents - $food - $drinks;
                }
            }

            $tx = null;
            if ($mapping->txIndex !== null && trim($cells[$mapping->txIndex]) !== '') {
                $raw = trim($cells[$mapping->txIndex]);
                if (! preg_match('/^\d{1,9}$/', $raw)) {
                    $problem($number, $mapping->txColumn, 'tx_count_invalid');

                    continue;
                }
                $tx = (int) $raw;
            }

            $covers = null;
            if ($mapping->coversIndex !== null && trim($cells[$mapping->coversIndex]) !== '') {
                $raw = trim($cells[$mapping->coversIndex]);
                if (! preg_match('/^\d{1,6}$/', $raw) || (int) $raw > self::MAX_COVERS) {
                    $problem($number, $mapping->coversColumn, 'covers_invalid');

                    continue;
                }
                $covers = (int) $raw;
            }

            if ($cents === null && $covers === null) {
                continue; // a covers-only file's blank day: nothing to stage
            }

            $rows[$date->toDateString()] = ['revenue_cents' => $cents, 'gst_inclusive' => $mapping->gstInclusive, 'tx_count' => $tx,
                'food_cents' => $food, 'drinks_cents' => $drinks, 'other_cents' => $other, 'covers' => $covers];
        }

        return [$rows, $problems];
    }
}
