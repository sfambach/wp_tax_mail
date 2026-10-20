<?php declare(strict_types=1);

namespace TaxMail\Core\Imap;

/**
 * The byte pipe to the mail server — an interface so the client can be tested against a scripted server.
 */
interface ImapStream
{
    /** Sends one line; the stream adds CRLF. */
    public function writeLine(string $line): void;

    /** One line without its CRLF; throws when the server hung up. */
    public function readLine(): string;

    /** Exactly $length bytes, for an IMAP literal `{n}`. */
    public function readBytes(int $length): string;

    public function close(): void;
}
