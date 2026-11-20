<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\MimeParser;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Mailbox\NonOrderSort;
use TaxMail\Core\Mailbox\SortRules;

/** The order of D-1516: his rules → pickup notice stays → no receipt is discarded → the outcome's folder. */
final class NonOrderSortTest extends TestCase
{
    private const PICKUP = 'Ihre Amazon Sendung liegt in einer Packstation';

    public function testHisRuleComesFirst(): void
    {
        $sort = new NonOrderSort(SortRules::fromText('dhl.de | Packstation | Pakete'), new FolderPlan());

        self::assertSame('Pakete', $sort->target(self::mail(self::PICKUP), true, null));
    }

    public function testPickupNoticeStaysBeforeDiscarding(): void
    {
        $sort = new NonOrderSort(new SortRules(), new FolderPlan());

        self::assertNull($sort->target(self::mail(self::PICKUP), true, 'Taxmod/Nicht erkannt'));
    }

    public function testOtherNoticeIsDiscarded(): void
    {
        $sort = new NonOrderSort(new SortRules(), new FolderPlan());

        self::assertSame('Taxmod/Verworfen', $sort->target(self::mail('Ihre Sendung kommt heute'), true, null));
        self::assertSame('Taxmod/Nicht erkannt', $sort->target(self::mail('Hallo'), false, 'Taxmod/Nicht erkannt'));
    }

    private static function mail(string $subject): MailMessage
    {
        return (new MimeParser())->parse(1, "From: DHL <zustellung@dhl.de>\r\nSubject: $subject\r\nContent-Type: text/plain\r\n\r\nSendungsnummer 00340435086270889742\r\n");
    }
}
