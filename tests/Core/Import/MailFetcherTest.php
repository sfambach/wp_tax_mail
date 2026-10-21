<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Import;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Import\FetchOutcome;
use TaxMail\Core\Import\MailFetcher;
use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\ParserException;
use TaxMail\Core\Parser\ParserRegistry;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use TaxMail\Tests\Core\Imap\ScriptedStream;

final class MailFetcherTest extends TestCase
{
    public function testEachMailGetsAnOutcome(): void
    {
        $mails = [
            11 => "From: a@shop.de\nSubject: Bestellung 1\nMessage-ID: <m11@shop.de>\n\nx",
            12 => "From: a@shop.de\nSubject: Newsletter\n\nx",
            13 => "From: a@shop.de\nSubject: kaputt\n\nx",
            14 => "From: b@other.de\nSubject: Hallo\n\nx",
        ];
        $script = "* OK\r\n* SEARCH 11 12 13 14\r\nA1 OK\r\n";
        $tag = 2;

        foreach ($mails as $uid => $raw) {
            $script .= "* 1 FETCH (UID $uid BODY[] {" . strlen($raw) . "}\r\n$raw)\r\nA" . $tag++ . " OK\r\n";
        }

        $fetched = (new MailFetcher(new ParserRegistry([self::shop()], ['shop' => true])))
            ->fetch(new ImapClient(new ScriptedStream($script)), 10, 50);

        self::assertSame(
            [FetchOutcome::Proposed, FetchOutcome::NoReceipt, FetchOutcome::Failed, FetchOutcome::Unrecognised],
            array_map(static fn ($result) => $result->outcome, $fetched)
        );
        self::assertSame(['1', 'mail', '<m11@shop.de>'], [$fetched[0]->proposal?->orderNumber, $fetched[0]->proposal?->source, $fetched[0]->proposal?->externalId]);
        self::assertSame('unreadable', $fetched[2]->error);
    }

    private static function shop(): OrderMailParser
    {
        return new class () implements OrderMailParser {
            public function key(): string
            {
                return 'shop';
            }

            public function shopName(): string
            {
                return 'Shop';
            }

            public function recognises(MailMessage $mail): bool
            {
                return $mail->senderDomain() === 'shop.de';
            }

            public function parse(MailMessage $mail): OrderProposal
            {
                return match ($mail->subject) {
                    'Bestellung 1' => new OrderProposal('Shop', '1', ProposalKind::Ordered),
                    'Newsletter'   => new OrderProposal('Shop', '', ProposalKind::NoReceipt),
                    default        => throw new ParserException('unreadable'),
                };
            }
        };
    }
}
