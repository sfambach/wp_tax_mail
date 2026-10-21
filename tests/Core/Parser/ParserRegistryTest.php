<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Parser;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\ParserRegistry;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

final class ParserRegistryTest extends TestCase
{
    public function testOnlySwitchedOnParsersRun(): void
    {
        $registry = new ParserRegistry(
            [self::parser('ebay', 'ebay.de'), self::parser('reichelt', 'reichelt.de'), self::parser('mouser', 'mouser.com')],
            ['ebay' => false, 'reichelt' => true]
        );

        self::assertFalse($registry->isActive('ebay'));
        self::assertTrue($registry->isActive('reichelt'));
        self::assertFalse($registry->isActive('mouser'));
        self::assertNull($registry->parserFor(self::mail('order@ebay.de')));
        self::assertSame('reichelt', $registry->parserFor(self::mail('shop@reichelt.de'))?->key());
        self::assertNull($registry->parserFor(self::mail('service@mouser.com')));
    }

    private static function mail(string $from): MailMessage
    {
        return new MailMessage(1, $from, 'Bestellung', null, '', '');
    }

    private static function parser(string $key, string $domain): OrderMailParser
    {
        return new class ($key, $domain) implements OrderMailParser {
            public function __construct(private readonly string $key, private readonly string $domain)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function shopName(): string
            {
                return ucfirst($this->key);
            }

            public function recognises(MailMessage $mail): bool
            {
                return $mail->senderDomain() === $this->domain;
            }

            public function parse(MailMessage $mail): OrderProposal
            {
                return new OrderProposal($this->key, '1', ProposalKind::Ordered);
            }
        };
    }
}
