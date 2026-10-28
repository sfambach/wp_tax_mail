<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\MimeParser;
use TaxMail\Core\Mailbox\CarrierNotice;

final class CarrierNoticeTest extends TestCase
{
    public function testParcelServiceNoticeIsOne(): void
    {
        self::assertTrue(CarrierNotice::is(self::mail('DHL Zustell-Update <zustellung@dhl.de>', 'Ihre Conrad Electronic SE Sendung kommt heute')));
        self::assertTrue(CarrierNotice::is(self::mail('DPD <noreply@service.dpd.de>', 'Ihr Paket kommt heute')));
    }

    public function testShopMailIsNone(): void
    {
        self::assertFalse(CarrierNotice::is(self::mail('"Amazon.de" <order-update@amazon.de>', 'Zugestellt: «PRINOX»')));
        self::assertFalse(CarrierNotice::is(self::mail('Someone <x@notdhl.de>', 'Hallo')));
    }

    private static function mail(string $from, string $subject): \Taxmod\Core\Import\Mail\MailMessage
    {
        return (new MimeParser())->parse(1, "From: $from\r\nSubject: $subject\r\nContent-Type: text/plain\r\n\r\nSendungsnummer 00340435086270889742\r\n");
    }
}
