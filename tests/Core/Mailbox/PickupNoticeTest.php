<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\MimeParser;
use TaxMail\Core\Mailbox\PickupNotice;

final class PickupNoticeTest extends TestCase
{
    public function testPickupNoticeIsOne(): void
    {
        self::assertTrue(PickupNotice::is(self::mail('Ihre Amazon Sendung liegt in einer Packstation')));
        self::assertTrue(PickupNotice::is(self::mail('Ihre Sendung liegt in einer Filiale zur Abholung bereit')));
        self::assertTrue(PickupNotice::is(self::mail('Ihr Paket liegt im GLS PaketShop')));
        self::assertTrue(PickupNotice::is(self::mail('Ihr Paket ist abholbereit')));
        self::assertTrue(PickupNotice::is(self::mail('IHRE SENDUNG LIEGT IN DER POSTFILIALE')));
        self::assertTrue(PickupNotice::is(self::mail('Ihre Sendung liegt im Paket Shop für Sie bereit – bitte abholen')));
        self::assertTrue(PickupNotice::is(self::mail('=?UTF-8?Q?Ihre_Sendung_liegt_zur_Abholung_f=C3=BCr_Sie_bereit?=')));
    }

    public function testOtherNoticeIsNone(): void
    {
        self::assertFalse(PickupNotice::is(self::mail('Ihre Conrad Electronic SE Sendung kommt heute')));
        self::assertFalse(PickupNotice::is(self::mail('Ihre AMAZON Sendung wurde zugestellt')));
        self::assertFalse(PickupNotice::is(self::mail('Ihre Abholung wurde storniert')));
        self::assertFalse(PickupNotice::is(self::mail('Paketabholung abgesagt')));
        self::assertFalse(PickupNotice::is(self::mail('Ihre Sendung wird zugestellt – Zustellung an die Haustür')));
    }

    public function testAddressInBodyDoesNotCount(): void
    {
        self::assertFalse(PickupNotice::is((new MimeParser())->parse(1, "From: x@amazon.de\r\nSubject: Bestellt: Kabel\r\nContent-Type: text/plain\r\n\r\nPackstation 123\r\n")));
    }

    private static function mail(string $subject): MailMessage
    {
        return (new MimeParser())->parse(1, "From: DHL <zustellung@dhl.de>\r\nSubject: $subject\r\nContent-Type: text/plain\r\n\r\nSendungsnummer 00340435086270889742\r\n");
    }
}
