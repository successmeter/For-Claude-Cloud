<?php
// api/app/Ingest/Csv/CsvProblem.php
namespace App\Ingest\Csv;

/** A file the reader will not parse. `problem` is the problem type; `row` a spreadsheet row number. */
class CsvProblem extends \RuntimeException
{
    public function __construct(public readonly string $problem, string $message, public readonly int $status = 422, public readonly ?int $row = null)
    {
        parent::__construct($message);
    }
}
