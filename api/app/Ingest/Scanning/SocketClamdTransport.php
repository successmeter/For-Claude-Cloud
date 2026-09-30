<?php
// api/app/Ingest/Scanning/SocketClamdTransport.php
namespace App\Ingest\Scanning;

/** clamd over a unix (unix:///path) or TCP (tcp://host:port) socket, with one timeout for connect and I/O. */
class SocketClamdTransport implements ClamdTransport
{
    public function __construct(private string $address, private int $timeoutSeconds = 10) {}

    public function exchange(string $request): string
    {
        $socket = @stream_socket_client($this->address, $errno, $errstr, $this->timeoutSeconds);
        if ($socket === false) {
            throw new ClamdUnavailable("clamd unreachable: {$errstr}");
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);
            for ($written = 0; $written < strlen($request); $written += $n) {
                $n = @fwrite($socket, substr($request, $written));
                if ($n === false || $n === 0) {
                    throw new ClamdUnavailable('clamd write failed');
                }
            }

            $reply = '';
            while (! feof($socket) && ! str_contains($reply, "\0")) {
                $chunk = fread($socket, 4096);
                if ($chunk === false || stream_get_meta_data($socket)['timed_out']) {
                    throw new ClamdUnavailable('clamd timed out');
                }
                $reply .= $chunk;
            }

            return $reply;
        } finally {
            fclose($socket);
        }
    }
}
