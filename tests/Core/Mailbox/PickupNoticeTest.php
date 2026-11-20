<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\MimeParser;
use TaxMail\Core\Mailbox\PickupNotice;

final class PickupNoticeTest extends TestCase
{
    public function testParcelWaitingForPickupIsOne(): void
    {
        self::assertTrue(PickupNotice::is(self::mail('Ihre Amazon Sendung liegt in einer Packstation')));
        self::assertTrue(PickupNotice::is(self::mail('Ihr Paket liegt in der Filiale zur Abholung bereit')));
    }

    public function testOtherParcelNoticeIsNone(): void
    {
        self::assertFalse(PickupNotice::is(self::mail('Ihre Conrad Electronic SE Sendung kommt heute')));
        self::assertFalse(PickupNotice::is(self::mail('Ihre AMAZON Sendung wurde zugestellt')));
    }

    private static function mail(string $subject): MailMessage
    {
        return (new MimeParser())->parse(1, "From: DHL Zustell-Update <zustellung@dhl.de>\r\nSubject: $subject\r\nContent-Type: text/plain\r\n\r\nSendungsnummer 00340435086270889742\r\n");
    }
}
