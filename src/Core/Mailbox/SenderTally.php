<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use Taxmod\Core\Import\Mail\MimeParser;

/**
 * Counts the senders of a folder from their «From» headers, most frequent first — the list he picks sorting rules from
 * (D-949).
 */
final class SenderTally
{
    /**
     * @param list<string> $fromHeaders raw header blocks as ImapClient::fromHeaders() returns them
     * @return array<string, int> sender address => number of mails
     */
    public static function count(array $fromHeaders, int $limit = 50): array
    {
        $parser = new MimeParser();
        $counts = [];

        foreach ($fromHeaders as $header) {
            $address = $parser->parse(0, rtrim($header) . "\r\n\r\n")->fromAddress;
            $address = $address === '' ? '?' : $address;
            $counts[$address] = ($counts[$address] ?? 0) + 1;
        }

        // Untyped: a sender like «12345» becomes an integer key.
        uksort($counts, static fn ($a, $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return array_slice($counts, 0, $limit, true);
    }
}
