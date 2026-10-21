<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Mailbox\SenderTally;
use TaxMail\Core\Mailbox\SortRules;

final class SortRulesTest extends TestCase
{
    public function testFirstMatchingLineWinsAndBadLinesAreDropped(): void
    {
        $rules = SortRules::fromText("newsletter@shop.de | | Verworfen\n| Ihr Paket | Taxmod/Versand\nnur zwei | Spalten\n | | Leer\nPOLLIN | Angebot | Werbung/");

        self::assertCount(3, $rules->rules);
        self::assertSame('Verworfen', $rules->folderFor('newsletter@shop.de', 'Ihr Paket ist da'));
        self::assertSame('Taxmod/Versand', $rules->folderFor('dhl@dhl.de', 'IHR PAKET kommt'));
        self::assertSame('Werbung', $rules->folderFor('info@pollin.de', 'Angebot der Woche'));
        self::assertNull($rules->folderFor('info@pollin.de', 'Rechnung'));
        self::assertSame("newsletter@shop.de |  | Verworfen\n | Ihr Paket | Taxmod/Versand\nPOLLIN | Angebot | Werbung", $rules->toText());
    }

    public function testShortNamesStandForTheConfiguredFolders(): void
    {
        $plan = new FolderPlan('INBOX', 'Taxmod/Importiert', 'Taxmod/Verworfen', 'Taxmod/Nicht erkannt');
        $rules = SortRules::fromText("a | | verworfen\nb | | Nicht erkannt\nc | | Privat/Shops");

        self::assertSame(['Taxmod/Verworfen', 'Taxmod/Nicht erkannt', 'Privat/Shops'], $rules->folders($plan));
    }

    public function testSendersAreCountedMostFrequentFirst(): void
    {
        $tally = SenderTally::count([
            "From: Pollin <info@pollin.de>\r\n", "From: =?UTF-8?Q?M=C3=BCller?= <a@b.de>\r\n", "From: info@pollin.de\r\n", "From: 12345\r\n",
        ]);

        self::assertSame(['info@pollin.de' => 2, 12345 => 1, 'a@b.de' => 1], $tally);
    }
}
