<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Mailbox\PickupNotice;
use TaxMail\Core\Mailbox\PickupRescue;
use TaxMail\Tests\Core\Imap\ScriptedStream;

final class PickupRescueTest extends TestCase
{
    public function testOnlyPickupNoticesGoBackToTheInbox(): void
    {
        $searches = count(PickupNotice::searchTerms());
        $script = "* OK\r\n* SEARCH 6 5\r\nA1 OK\r\n";

        for ($tag = 2; $tag <= $searches; $tag++) {
            $script .= "* SEARCH 5\r\nA$tag OK\r\n";
        }

        $pickup = self::raw('Ihre Amazon Sendung liegt in einer Packstation');
        $calledOff = self::raw('Ihre Abholung wurde storniert');
        $next = $searches + 1;
        $script .= "* 1 FETCH (UID 5 BODY[] {" . strlen($pickup) . "}\r\n$pickup)\r\nA$next OK\r\n";
        $script .= "* CAPABILITY IMAP4rev1 MOVE\r\nA" . ($next + 1) . " OK\r\nA" . ($next + 2) . " OK\r\n";
        $script .= "* 2 FETCH (UID 6 BODY[] {" . strlen($calledOff) . "}\r\n$calledOff)\r\nA" . ($next + 3) . " OK\r\n";
        $stream = new ScriptedStream($script);

        self::assertSame([5], (new PickupRescue())->run(new ImapClient($stream), 'INBOX', 60));
        self::assertSame('A1 UID SEARCH SUBJECT "packstation"', $stream->written[0]);
        self::assertContains('A' . ($next + 2) . ' UID MOVE 5 "INBOX"', $stream->written);
        self::assertNotContains('A' . ($next + 4) . ' UID MOVE 6 "INBOX"', $stream->written);
        self::assertCount($next + 3, $stream->written);
    }

    private static function raw(string $subject): string
    {
        return "From: DHL <zustellung@dhl.de>\r\nSubject: $subject\r\nContent-Type: text/plain\r\n\r\nHallo\r\n";
    }
}
