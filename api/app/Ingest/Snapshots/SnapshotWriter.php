<?php
// api/app/Ingest/Snapshots/SnapshotWriter.php
namespace App\Ingest\Snapshots;

use App\Services\Encryption\EnvelopeEncryptor;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the canonical CSV of an upload's mapped columns, envelope-encrypted with the org's data
 * key, to the `snapshots` disk (Plan C design §3.4). The original file is never stored.
 */
class SnapshotWriter
{
    public function __construct(private EnvelopeEncryptor $encryptor) {}

    /**
     * @param  list<object>  $rows  business_date, revenue_cents, gst_inclusive, tx_count; in date order
     * @return array{path: string, sha256: string, bytes: int}
     */
    public function write(string $orgId, string $runId, array $rows): array
    {
        $csv = "business_date,revenue_cents,gst_inclusive,tx_count\n";
        foreach ($rows as $r) {
            $csv .= sprintf("%s,%d,%s,%s\n", $r->business_date, $r->revenue_cents, $r->gst_inclusive ? 'true' : 'false', $r->tx_count ?? '');
        }

        $path = "{$orgId}/{$runId}.csv.enc";
        Storage::disk('snapshots')->put($path, $this->encryptor->encrypt($orgId, $csv));

        return ['path' => $path, 'sha256' => hash('sha256', $csv), 'bytes' => strlen($csv)];
    }

    public function delete(string $path): void
    {
        Storage::disk('snapshots')->delete($path);
    }
}
