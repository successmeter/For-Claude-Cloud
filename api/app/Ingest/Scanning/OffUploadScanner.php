<?php
// api/app/Ingest/Scanning/OffUploadScanner.php
namespace App\Ingest\Scanning;

use Illuminate\Support\Facades\Log;

/**
 * Scanning deliberately off (INGEST_SCANNER=off) on a host that can't run clamd, such as the Laravel
 * Cloud trial. Unlike NullUploadScanner it may run deployed, and it logs every upload it lets through
 * unscanned, so the gap stays visible. Turn ClamAV back on before public launch.
 */
class OffUploadScanner implements UploadScanner
{
    public function scan(string $bytes): ScanResult
    {
        Log::warning('ingest.upload_not_scanned', ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]);

        return ScanResult::clean();
    }
}
