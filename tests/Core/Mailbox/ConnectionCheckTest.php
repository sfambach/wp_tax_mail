<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mailbox;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Mailbox\ConnectionCheck;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Tests\Core\Imap\ScriptedStream;

final class ConnectionCheckTest extends TestCase
{
    public function testCreatesParentThenMissingFoldersWithTheServerDelimiter(): void
    {
        $stream = new ScriptedStream(
            "* OK\r\n"
            . "* LIST (\\Noselect) \".\" \"\"\r\nA1 OK\r\n"
            . "* LIST () \".\" \"INBOX\"\r\n* LIST () \".\" \"Taxmod\"\r\n* LIST () \".\" \"Taxmod.Importiert\"\r\nA2 OK\r\n"
            . "A3 OK\r\nA4 OK\r\n"
            . "* 5 EXISTS\r\nA5 OK\r\n"
            . "* CAPABILITY IMAP4rev1 MOVE\r\nA6 OK\r\n"
        );

        $result = (new ConnectionCheck(new FolderPlan()))->run(new ImapClient($stream));

        self::assertSame(['Taxmod.Verworfen', 'Taxmod.Nicht erkannt'], $result['created']);
        self::assertSame(['Taxmod.Importiert'], $result['present']);
        self::assertSame(5, $result['inbox_count']);
        self::assertTrue($result['can_move']);
        self::assertSame('A3 CREATE "Taxmod.Verworfen"', $stream->written[2]);
    }
}
