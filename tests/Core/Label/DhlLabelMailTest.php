<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Label;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Label\DhlLabelMail;
use Taxmod\Core\Import\Mail\MimeParser;

final class DhlLabelMailTest extends TestCase
{
    public function testLabelMailGivesCartDateAndOnlyTheLabelLink(): void
    {
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/../Parser/fixtures/d1.eml'));
        $label = (new DhlLabelMail())->read($mail);

        self::assertSame([
            'cart'  => '7BQCFDV5F9Z8',
            'date'  => '2026-10-23T18:20:40+02:00',
            'links' => ['https://www.dhl.de/int-versenden/gw/rest/init?token=abc123&download=letter&pos=1'],
        ], $label?->toArray());
    }

    public function testForwardedCopyIsALabelToo(): void
    {
        $raw = str_replace(['From: noreply@dhl.com', 'Subject: '], ['From: Stefan <stefan@example.de>', 'Subject: WG: '], (string) file_get_contents(__DIR__ . '/../Parser/fixtures/d1.eml'));

        self::assertSame('7BQCFDV5F9Z8', (new DhlLabelMail())->read((new MimeParser())->parse(1, $raw))?->cart);
    }

    public function testOtherMailsAreNoLabel(): void
    {
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/../Parser/fixtures/e2.eml'));

        self::assertNull((new DhlLabelMail())->read($mail));
    }
}
