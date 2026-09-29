<?php
// api/app/Ingest/Scanning/ClamdTransport.php
namespace App\Ingest\Scanning;

/** Sends one request to clamd and returns its reply. Throws ClamdUnavailable on I/O failure. */
interface ClamdTransport
{
    public function exchange(string $request): string;
}
