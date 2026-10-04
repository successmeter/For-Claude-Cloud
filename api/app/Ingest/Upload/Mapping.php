<?php
// api/app/Ingest/Upload/Mapping.php
namespace App\Ingest\Upload;

use App\Ingest\Csv\CsvTable;

/**
 * A confirmed mapping: header names as written in the file, a known date format, the GST basis.
 * Revenue is required unless the file only carries covers (Plan E); food and drinks go together and
 * need the revenue column (the remainder is Other).
 */
final class Mapping
{
    private function __construct(
        public readonly string $dateColumn,
        public readonly int $dateIndex,
        public readonly string $dateFormat,
        public readonly ?string $revenueColumn,
        public readonly ?int $revenueIndex,
        public readonly ?string $txColumn,
        public readonly ?int $txIndex,
        public readonly bool $gstInclusive,
        public readonly ?string $coversColumn = null,
        public readonly ?int $coversIndex = null,
        public readonly ?string $foodColumn = null,
        public readonly ?int $foodIndex = null,
        public readonly ?string $drinksColumn = null,
        public readonly ?int $drinksIndex = null,
    ) {}

    /** @throws MappingInvalid */
    public static function fromInput(array $input, CsvTable $table): self
    {
        $column = function (string $key, bool $required) use ($input, $table): ?array {
            $name = $input[$key] ?? null;
            if ($name === null || $name === '') {
                if ($required) {
                    throw new MappingInvalid("{$key} is required.");
                }

                return null;
            }
            $index = is_string($name) ? $table->column($name) : null;
            if ($index === null) {
                throw new MappingInvalid("{$key} is not a column in the file.");
            }

            return [$name, $index];
        };

        [$date, $dateIndex] = $column('date_column', true);
        [$covers, $coversIndex] = $column('covers_column', false) ?? [null, null];
        [$revenue, $revenueIndex] = $column('revenue_column', $covers === null) ?? [null, null];
        [$tx, $txIndex] = $column('tx_count_column', false) ?? [null, null];
        [$food, $foodIndex] = $column('food_column', false) ?? [null, null];
        [$drinks, $drinksIndex] = $column('drinks_column', false) ?? [null, null];

        if (($food === null) !== ($drinks === null)) {
            throw new MappingInvalid('food_column and drinks_column go together.');
        }
        if ($revenue === null && ($food !== null || $tx !== null)) {
            throw new MappingInvalid('revenue_column is required with food, drinks or transactions.');
        }
        $mapped = array_values(array_filter([$date, $covers, $revenue, $tx, $food, $drinks], fn ($c) => $c !== null));
        if (count(array_unique($mapped)) !== count($mapped)) {
            throw new MappingInvalid('Each mapped column must be different.');
        }
        if (! in_array($input['date_format'] ?? null, DateFormats::names(), true)) {
            throw new MappingInvalid('date_format must be one of '.implode(', ', DateFormats::names()).'.');
        }
        $gst = filter_var($input['gst_inclusive'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($gst === null) {
            throw new MappingInvalid('gst_inclusive must be true or false.');
        }

        return new self($date, $dateIndex, $input['date_format'], $revenue, $revenueIndex, $tx, $txIndex, $gst,
            $covers, $coversIndex, $food, $foodIndex, $drinks, $drinksIndex);
    }

    public const KEYS = ['date_column', 'date_format', 'revenue_column', 'tx_count_column', 'gst_inclusive', 'covers_column', 'food_column', 'drinks_column'];

    public function toArray(): array
    {
        return [
            'date_column' => $this->dateColumn,
            'date_format' => $this->dateFormat,
            'revenue_column' => $this->revenueColumn,
            'tx_count_column' => $this->txColumn,
            'gst_inclusive' => $this->gstInclusive,
            'covers_column' => $this->coversColumn,
            'food_column' => $this->foodColumn,
            'drinks_column' => $this->drinksColumn,
        ];
    }
}
