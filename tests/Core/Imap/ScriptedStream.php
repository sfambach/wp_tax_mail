<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Imap;

use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\ImapStream;

/**
 * A mail server that answers from a script: what the client wrote is recorded, what it reads comes from the queue.
 */
final class ScriptedStream implements ImapStream
{
    /** @var list<string> */
    public array $written = [];

    private string $buffer;

    public function __construct(string $serverOutput)
    {
        $this->buffer = $serverOutput;
    }

    public function feed(string $serverOutput): void
    {
        $this->buffer .= $serverOutput;
    }

    public function writeLine(string $line): void
    {
        $this->written[] = $line;
    }

    public function readLine(): string
    {
        $end = strpos($this->buffer, "\r\n");

        if ($end === false) {
            throw new ImapException('script exhausted');
        }

        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);

        return $line;
    }

    public function readBytes(int $length): string
    {
        $bytes = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $bytes;
    }

    public function close(): void
    {
    }
}
