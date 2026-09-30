<?php
// api/app/Ingest/Upload/UploadIntake.php
namespace App\Ingest\Upload;

use App\Hub\Exceptions\HubProblem;
use App\Ingest\Csv\CsvProblem;
use App\Ingest\Csv\CsvReader;
use App\Ingest\Scanning\ScanResult;
use App\Ingest\Scanning\UploadScanner;
use Illuminate\Http\Request;

/**
 * Checks, scans and parses the `file` of an upload request (Plan C design §4.1), for both inspect
 * and upload. The file is read into memory and never written anywhere.
 */
class UploadIntake
{
    public function __construct(private UploadScanner $scanner) {}

    /** @throws HubProblem|UploadInfected */
    public function receive(Request $request): ReceivedFile
    {
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');

        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw new HubProblem(415, 'unsupported_media_type', 'Upload a .csv file.');
        }
        if ($file->getSize() > config('ingest.max_bytes')) {
            throw new HubProblem(413, 'file_too_large', 'The file is larger than the limit.');
        }

        $bytes = $file->get();
        $sha256 = hash('sha256', $bytes);

        $scan = $this->scanner->scan($bytes);
        if ($scan->status === ScanResult::INFECTED) {
            throw new UploadInfected($scan->signature, $sha256);
        }
        if ($scan->status !== ScanResult::CLEAN) {
            throw new HubProblem(503, 'scanner_unavailable', 'Uploads cannot be checked right now. Try again shortly.');
        }

        try {
            $table = CsvReader::fromConfig()->read($bytes);
        } catch (CsvProblem $e) {
            throw new HubProblem($e->status, $e->problem, $e->getMessage(), $e->row === null ? [] : ['row' => $e->row]);
        }

        return new ReceivedFile($table, $sha256, strlen($bytes));
    }
}
