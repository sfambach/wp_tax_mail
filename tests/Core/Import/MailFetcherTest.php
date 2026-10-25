<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Import;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Import\FetchOutcome;
use TaxMail\Core\Import\MailFetcher;
use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\MultiOrderMailParser;
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

    public function testStatusMailsCarryNoOrderDate(): void
    {
        $script = "* OK\r\n* SEARCH 11 12\r\nA1 OK\r\n";
        $tag = 2;

        foreach ([11 => 'Bestellung 1', 12 => 'Versandt 1'] as $uid => $subject) {
            $raw = "From: a@shop.de\nSubject: $subject\n\nx";
            $script .= "* 1 FETCH (UID $uid BODY[] {" . strlen($raw) . "}\r\n$raw)\r\nA" . $tag++ . " OK\r\n";
        }

        $fetched = (new MailFetcher(new ParserRegistry([self::shop()], ['shop' => true])))
            ->fetch(new ImapClient(new ScriptedStream($script)), 10, 50);

        self::assertSame(['2026-10-22', null], [$fetched[0]->proposal?->orderDate, $fetched[1]->proposal?->orderDate]);
        self::assertSame('2026-10-23', $fetched[1]->proposal?->shippedDate);
    }

    public function testAMailWithSeveralOrdersGivesEachOne(): void
    {
        $raw = "From: a@multi.de\nSubject: Bestellt\nMessage-ID: <m@multi.de>\n\nx";
        $script = "* OK\r\n* SEARCH 11\r\nA1 OK\r\n* 1 FETCH (UID 11 BODY[] {" . strlen($raw) . "}\r\n$raw)\r\nA2 OK\r\n";
        $multi = new class () implements MultiOrderMailParser {
            public function key(): string { return 'multi'; }
            public function shopName(): string { return 'Multi'; }
            public function recognises(MailMessage $mail): bool { return true; }
            public function parse(MailMessage $mail): OrderProposal { return $this->parseAll($mail)[0]; }
            public function parseAll(MailMessage $mail): array
            {
                return [new OrderProposal('Multi', '1', ProposalKind::Ordered), new OrderProposal('Multi', '2', ProposalKind::Ordered)];
            }
        };

        $fetched = (new MailFetcher(new ParserRegistry([$multi], ['multi' => true])))->fetch(new ImapClient(new ScriptedStream($script)), 10, 50);

        self::assertSame(['1', '2'], array_map(static fn (OrderProposal $order): string => $order->orderNumber, $fetched[0]->proposals));
        self::assertSame(['mail', 'mail'], array_map(static fn (OrderProposal $order): ?string => $order->source, $fetched[0]->proposals));
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
                    'Bestellung 1' => new OrderProposal('Shop', '1', ProposalKind::Ordered, '2026-10-22'),
                    'Versandt 1'   => new OrderProposal('Shop', '1', ProposalKind::Shipped, '2026-10-23', shippedDate: '2026-10-23'),
                    'Newsletter'   => new OrderProposal('Shop', '', ProposalKind::NoReceipt),
                    default        => throw new ParserException('unreadable'),
                };
            }
        };
    }
}
