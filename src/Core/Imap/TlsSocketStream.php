<?php declare(strict_types=1);

namespace TaxMail\Core\Imap;

/**
 * IMAP over implicit TLS (port 993) with plain PHP streams — the `imap` extension left PHP with 8.4 (D-942).
 */
final class TlsSocketStream implements ImapStream
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port, int $timeoutSeconds = 20)
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $errorNumber = 0;
        $errorText = '';
        $socket = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errorNumber,
            $errorText,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new ImapException(sprintf('connect %s:%d failed: %s (%d)', $host, $port, $errorText, $errorNumber));
        }

        stream_set_timeout($socket, $timeoutSeconds);
        $this->socket = $socket;
    }

    public function writeLine(string $line): void
    {
        if (@fwrite($this->socket, $line . "\r\n") === false) {
            throw new ImapException('write to server failed');
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->socket);

        if ($line === false) {
            throw new ImapException(stream_get_meta_data($this->socket)['timed_out'] ? 'server timed out' : 'server closed the connection');
        }

        return rtrim($line, "\r\n");
    }

    public function readBytes(int $length): string
    {
        $bytes = '';

        while (strlen($bytes) < $length) {
            $chunk = fread($this->socket, $length - strlen($bytes));

            if ($chunk === false || $chunk === '') {
                throw new ImapException('server closed the connection inside a literal');
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }

    public function close(): void
    {
        fclose($this->socket);
    }
}
