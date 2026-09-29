<?php
// api/app/Ingest/Upload/Mapping.php
namespace App\Ingest\Upload;

use App\Ingest\Csv\CsvTable;

/** A confirmed mapping: header names as written in the file, a known date format, the GST basis. */
final class Mapping
{
    private function __construct(
        public readonly string $dateColumn,
        public readonly int $dateIndex,
        public readonly string $dateFormat,
        public readonly string $revenueColumn,
        public readonly int $revenueIndex,
        public readonly ?string $txColumn,
        public readonly ?int $txIndex,
        public readonly bool $gstInclusive,
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
        [$revenue, $revenueIndex] = $column('revenue_column', true);
        [$tx, $txIndex] = $column('tx_count_column', false) ?? [null, null];
        if (count(array_unique(array_filter([$date, $revenue, $tx], fn ($c) => $c !== null))) !== count(array_filter([$date, $revenue, $tx], fn ($c) => $c !== null))) {
            throw new MappingInvalid('Each mapped column must be different.');
        }
        if (! in_array($input['date_format'] ?? null, DateFormats::names(), true)) {
            throw new MappingInvalid('date_format must be one of '.implode(', ', DateFormats::names()).'.');
        }
        $gst = filter_var($input['gst_inclusive'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($gst === null) {
            throw new MappingInvalid('gst_inclusive must be true or false.');
        }

        return new self($date, $dateIndex, $input['date_format'], $revenue, $revenueIndex, $tx, $txIndex, $gst);
    }

    public function toArray(): array
    {
        return [
            'date_column' => $this->dateColumn,
            'date_format' => $this->dateFormat,
            'revenue_column' => $this->revenueColumn,
            'tx_count_column' => $this->txColumn,
            'gst_inclusive' => $this->gstInclusive,
        ];
    }
}
