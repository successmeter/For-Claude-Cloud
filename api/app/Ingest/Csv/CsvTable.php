<?php
// api/app/Ingest/Csv/CsvTable.php
namespace App\Ingest\Csv;

final class CsvTable
{
    /**
     * @param  list<string>  $header  trimmed, unique (case-insensitive), non-empty
     * @param  list<list<string>>  $rows  cells as given, padded to the header's width
     * @param  list<int>  $rowNumbers  each row's number as a spreadsheet shows it (the header is row 1)
     */
    public function __construct(
        public readonly array $header,
        public readonly array $rows,
        public readonly array $rowNumbers,
        public readonly string $delimiter,
    ) {}

    public function column(string $name): ?int
    {
        $i = array_search($name, $this->header, true);

        return $i === false ? null : $i;
    }
}
