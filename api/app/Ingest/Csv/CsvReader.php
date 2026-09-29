<?php
// api/app/Ingest/Csv/CsvReader.php
namespace App\Ingest\Csv;

/**
 * Parses an uploaded CSV in memory under hard limits (Plan C design §4.1). Cells are returned as
 * plain strings: nothing is evaluated, so a formula is just text. UTF-8 only (a BOM is dropped);
 * comma, semicolon or tab, whichever the header line uses most.
 */
class CsvReader
{
    public function __construct(private int $maxBytes, private int $maxRows) {}

    public static function fromConfig(): self
    {
        return new self(config('ingest.max_bytes'), config('ingest.max_rows'));
    }

    public function read(string $bytes): CsvTable
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new CsvProblem('file_too_large', 'The file is larger than the limit.', 413);
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (! mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            throw new CsvProblem('encoding_not_utf8', 'The file is not UTF-8 text. Save it as "CSV UTF-8" and upload again.');
        }

        $delimiter = self::delimiter($bytes);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        try {
            $header = null;
            $rows = [];
            $numbers = [];
            $record = 0;
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $record++;
                if (self::blank($cells)) {
                    continue;
                }
                if ($header === null) {
                    $header = self::header($cells);

                    continue;
                }
                if (count($cells) > count($header)) {
                    throw new CsvProblem('row_too_long', 'A row has more cells than the header.', 422, $record);
                }
                if (count($rows) === $this->maxRows) {
                    throw new CsvProblem('too_many_rows', "The file has more than {$this->maxRows} rows.");
                }
                $rows[] = array_pad(array_map('strval', $cells), count($header), '');
                $numbers[] = $record;
            }
        } finally {
            fclose($stream);
        }

        if ($header === null) {
            throw new CsvProblem('empty_file', 'The file is empty.');
        }

        return new CsvTable($header, $rows, $numbers, $delimiter);
    }

    private static function delimiter(string $bytes): string
    {
        $firstLine = strtok($bytes, "\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    private static function blank(array $cells): bool
    {
        return $cells === [null] || trim(implode('', array_map('strval', $cells))) === '';
    }

    private static function header(array $cells): array
    {
        $header = array_map(fn ($c) => trim((string) $c), $cells);
        $lower = array_map('mb_strtolower', $header);
        if (in_array('', $header, true) || count(array_unique($lower)) !== count($lower)) {
            throw new CsvProblem('header_invalid', 'The first row must name every column, each once.', 422, 1);
        }

        return $header;
    }
}
