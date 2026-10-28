<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Parser;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\OrderMailParser;
use TaxMail\Core\Parser\ParserRegistry;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

final class ParserRegistryTest extends TestCase
{
    public function testEveryRegisteredReaderRuns(): void
    {
        $registry = new ParserRegistry([self::parser('ebay', 'ebay.de'), self::parser('reichelt', 'reichelt.de')]);

        self::assertSame('ebay', $registry->parserFor(self::mail('order@ebay.de'))?->key());
        self::assertSame('reichelt', $registry->parserFor(self::mail('shop@reichelt.de'))?->key());
        self::assertNull($registry->parserFor(self::mail('service@mouser.com')));
    }

    public function testTheSignatureFollowsModulesAndVersions(): void
    {
        $before = (new ParserRegistry([self::parser('reichelt', 'reichelt.de'), self::parser('ebay', 'ebay.de')]))->signature();

        self::assertSame('ebay@1.0.0,reichelt@1.0.0', $before);
        self::assertNotSame($before, (new ParserRegistry([self::parser('ebay', 'ebay.de'), self::parser('reichelt', 'reichelt.de', '1.0.1')]))->signature());
        self::assertNotSame($before, (new ParserRegistry([self::parser('ebay', 'ebay.de')]))->signature());
    }

    private static function mail(string $from): MailMessage
    {
        return new MailMessage(1, $from, 'Bestellung', null, '', '');
    }

    private static function parser(string $key, string $domain, string $version = '1.0.0'): OrderMailParser
    {
        return new class ($key, $domain, $version) implements OrderMailParser {
            public function __construct(private readonly string $key, private readonly string $domain, private readonly string $version)
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

            public function kinds(): array
            {
                return [ProposalKind::Ordered];
            }

            public function version(): string
            {
                return $this->version;
            }
        };
    }
}
