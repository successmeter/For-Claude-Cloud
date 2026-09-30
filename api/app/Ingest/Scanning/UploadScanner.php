<?php
// api/app/Ingest/Scanning/UploadScanner.php
namespace App\Ingest\Scanning;

/** Malware scan of an uploaded file, before it is parsed (Plan C design §4.1). */
interface UploadScanner
{
    public function scan(string $bytes): ScanResult;
}
