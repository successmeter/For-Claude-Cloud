<?php
// api/app/Ingest/Upload/ReceivedFile.php
namespace App\Ingest\Upload;

use App\Ingest\Csv\CsvTable;

/** An accepted, scanned and parsed upload. The bytes stay in memory and are never stored. */
final class ReceivedFile
{
    public function __construct(
        public readonly CsvTable $table,
        public readonly string $sha256,
        public readonly int $bytes,
    ) {}
}
