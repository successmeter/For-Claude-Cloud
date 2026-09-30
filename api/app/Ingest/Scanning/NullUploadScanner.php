<?php
// api/app/Ingest/Scanning/NullUploadScanner.php
namespace App\Ingest\Scanning;

/**
 * Passes everything. Local development and tests only: like LocalFileKmsDriver it refuses to exist
 * anywhere else, so a missing production scanner fails loudly instead of silently scanning nothing.
 */
class NullUploadScanner implements UploadScanner
{
    public function __construct(string $environment)
    {
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new \RuntimeException('NullUploadScanner must never be used outside local/testing environments.');
        }
    }

    public function scan(string $bytes): ScanResult
    {
        return ScanResult::clean();
    }
}
