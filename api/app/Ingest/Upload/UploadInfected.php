<?php
// api/app/Ingest/Upload/UploadInfected.php
namespace App\Ingest\Upload;

/** The scanner found malware. Callers audit it and answer without throwing, so the audit row is kept. */
class UploadInfected extends \RuntimeException
{
    public function __construct(public readonly string $signature, public readonly string $sha256)
    {
        parent::__construct('The file was rejected by the malware scan.');
    }
}
