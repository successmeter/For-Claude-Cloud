<?php
// api/tests/Unit/Ingest/ClamAvUploadScannerTest.php

namespace Tests\Unit\Ingest;

use App\Ingest\Scanning\ClamAvUploadScanner;
use App\Ingest\Scanning\ClamdTransport;
use App\Ingest\Scanning\ClamdUnavailable;
use App\Ingest\Scanning\NullUploadScanner;
use App\Ingest\Scanning\ScanResult;
use App\Ingest\Scanning\SocketClamdTransport;
use PHPUnit\Framework\TestCase;

class ClamAvUploadScannerTest extends TestCase
{
    private function transport(string|\Throwable $reply, ?string &$sent = null): ClamdTransport
    {
        return new class($reply, $sent) implements ClamdTransport
        {
            public function __construct(private string|\Throwable $reply, private ?string &$sent) {}

            public function exchange(string $request): string
            {
                $this->sent = $request;
                if ($this->reply instanceof \Throwable) {
                    throw $this->reply;
                }

                return $this->reply;
            }
        };
    }

    public function test_clean_file(): void
    {
        $result = (new ClamAvUploadScanner($this->transport("stream: OK\0", $sent), chunkBytes: 4))->scan('date,revenue');

        $this->assertSame(ScanResult::CLEAN, $result->status);
        // zINSTREAM, then length-prefixed chunks (big-endian uint32), then a zero-length terminator.
        $this->assertSame(
            "zINSTREAM\0".pack('N', 4).'date'.pack('N', 4).',rev'.pack('N', 4).'enue'.pack('N', 0),
            $sent,
        );
    }

    public function test_infected_file_reports_the_signature(): void
    {
        $result = (new ClamAvUploadScanner($this->transport("stream: Eicar-Test-Signature FOUND\0")))->scan('x');

        $this->assertSame(ScanResult::INFECTED, $result->status);
        $this->assertSame('Eicar-Test-Signature', $result->signature);
    }

    public function test_clamd_errors_and_timeouts_fail_closed(): void
    {
        foreach ([
            $this->transport("INSTREAM size limit exceeded. ERROR\0"),
            $this->transport(''),
            $this->transport(new ClamdUnavailable('timed out')),
        ] as $transport) {
            $this->assertSame(ScanResult::ERROR, (new ClamAvUploadScanner($transport))->scan('x')->status);
        }
    }

    public function test_the_socket_transport_talks_to_a_clamd_like_server(): void
    {
        $script = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            fwrite(STDOUT, stream_socket_get_name($server, false)."\n");
            fflush(STDOUT);
            $conn = stream_socket_accept($server, 10);
            $request = '';
            while (! str_ends_with($request, pack('N', 0))) { $request .= fread($conn, 8192); }
            fwrite($conn, str_starts_with($request, "zINSTREAM\0") ? "stream: OK\0" : "UNKNOWN COMMAND\0");
            fclose($conn);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
        $address = trim(fgets($pipes[1]));

        try {
            $scanner = new ClamAvUploadScanner(new SocketClamdTransport("tcp://{$address}", timeoutSeconds: 5));
            $this->assertSame(ScanResult::CLEAN, $scanner->scan(str_repeat('a', 100_000))->status);
        } finally {
            proc_close($process);
        }
    }

    public function test_an_unreachable_clamd_is_an_error(): void
    {
        $scanner = new ClamAvUploadScanner(new SocketClamdTransport('tcp://127.0.0.1:1', timeoutSeconds: 1));

        $this->assertSame(ScanResult::ERROR, $scanner->scan('x')->status);
    }

    public function test_the_null_scanner_refuses_to_run_outside_local_and_testing(): void
    {
        $this->assertSame(ScanResult::CLEAN, (new NullUploadScanner('testing'))->scan('x')->status);

        $this->expectException(\RuntimeException::class);
        new NullUploadScanner('production');
    }
}
