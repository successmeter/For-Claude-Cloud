<?php
// api/app/Ingest/Scanning/ClamAvUploadScanner.php
namespace App\Ingest\Scanning;

/**
 * Scans through clamd's zINSTREAM command: the file goes as chunks, each prefixed with its length
 * as a big-endian uint32, ended by a zero length. clamd answers "stream: OK", "stream: <sig> FOUND"
 * or "<message> ERROR". Anything but OK or FOUND is an error, and errors refuse the upload.
 */
class ClamAvUploadScanner implements UploadScanner
{
    public function __construct(private ClamdTransport $transport, private int $chunkBytes = 65536) {}

    public function scan(string $bytes): ScanResult
    {
        $request = "zINSTREAM\0";
        foreach (str_split($bytes, $this->chunkBytes) as $chunk) {
            $request .= pack('N', strlen($chunk)).$chunk;
        }
        $request .= pack('N', 0);

        try {
            $reply = trim($this->transport->exchange($request), "\0\r\n ");
        } catch (ClamdUnavailable) {
            return ScanResult::error();
        }

        if ($reply === 'stream: OK') {
            return ScanResult::clean();
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
            return ScanResult::infected($m[1]);
        }

        return ScanResult::error();
    }
}
