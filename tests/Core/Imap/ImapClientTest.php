<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Imap;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;

final class ImapClientTest extends TestCase
{
    public function testFetchReturnsTheLiteralAndPeeks(): void
    {
        $mail = "From: a@b\r\nSubject: x\r\n\r\nbody\r\n";
        $stream = new ScriptedStream("* OK ready\r\n* 3 FETCH (UID 42 BODY[] {" . strlen($mail) . "}\r\n" . $mail . ")\r\nA1 OK done\r\n");

        $raw = (new ImapClient($stream))->fetchRaw(42);

        self::assertSame($mail, $raw);
        self::assertSame(['A1 UID FETCH 42 (BODY.PEEK[]<0.2000000>)'], $stream->written);
    }

    public function testUidsAfterDropsTheHighestUidBelowTheRange(): void
    {
        $stream = new ScriptedStream("* OK\r\n* SEARCH 17\r\nA1 OK\r\n* SEARCH 18 21 19\r\nA2 OK\r\n");
        $client = new ImapClient($stream);

        self::assertSame([], $client->uidsAfter(17));
        self::assertSame([18, 19, 21], $client->uidsAfter(17));
    }

    public function testUidsContainingSearchesTheTextOnTheServer(): void
    {
        $stream = new ScriptedStream("* OK\r\n* SEARCH 905 17\r\nA1 OK\r\n");

        self::assertSame([17, 905], (new ImapClient($stream))->uidsContaining('3077100174357970'));
        self::assertSame('A1 UID SEARCH TEXT "3077100174357970"', $stream->written[0]);
    }

    public function testUidsFromSearchesTheSender(): void
    {
        $stream = new ScriptedStream("* OK\r\n* SEARCH 40 7\r\nA1 OK\r\n");

        self::assertSame([7, 40], (new ImapClient($stream))->uidsFrom('amazon.de'));
        self::assertSame('A1 UID SEARCH FROM "amazon.de"', $stream->written[0]);
    }

    public function testSelectReadsCountAndUidValidity(): void
    {
        $stream = new ScriptedStream("* OK\r\n* 12 EXISTS\r\n* OK [UIDVALIDITY 3857529045] UIDs valid\r\n* OK [UIDNEXT 4392] next\r\nA1 OK [READ-WRITE] done\r\n");

        $status = (new ImapClient($stream))->select('INBOX');

        self::assertSame(12, $status->messageCount);
        self::assertSame(3857529045, $status->uidValidity);
        self::assertSame(4392, $status->uidNext);
    }

    public function testFoldersDecodeUtf7AndLiterals(): void
    {
        $stream = new ScriptedStream(
            "* OK\r\n"
            . "* LIST (\\HasNoChildren) \".\" \"INBOX\"\r\n"
            . "* LIST (\\HasNoChildren) \".\" \"Taxmod.Gepr&APw-ft\"\r\n"
            . "* LIST (\\HasNoChildren) \".\" {11}\r\nwith \"quote\r\n"
            . "A1 OK\r\n"
        );

        self::assertSame(['INBOX', 'Taxmod.Geprüft', 'with "quote'], (new ImapClient($stream))->folders());
    }

    public function testMoveFallsBackToCopyAndUidExpunge(): void
    {
        $stream = new ScriptedStream("* OK\r\n* CAPABILITY IMAP4rev1 UIDPLUS\r\nA1 OK\r\nA2 OK\r\nA3 OK\r\nA4 OK\r\n");

        (new ImapClient($stream))->move(7, 'Taxmod.Importiert');

        self::assertSame([
            'A1 CAPABILITY',
            'A2 UID COPY 7 "Taxmod.Importiert"',
            'A3 UID STORE 7 +FLAGS.SILENT (\\Deleted)',
            'A4 UID EXPUNGE 7',
        ], $stream->written);
    }

    public function testMoveRefusesWithoutMoveOrUidplus(): void
    {
        $stream = new ScriptedStream("* OK\r\n* CAPABILITY IMAP4rev1\r\nA1 OK\r\n");

        $this->expectException(ImapException::class);
        (new ImapClient($stream))->move(7, 'X');
    }

    public function testRefusedLoginDoesNotRepeatThePassword(): void
    {
        $stream = new ScriptedStream("* OK\r\nA1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");

        try {
            (new ImapClient($stream))->login('einkauf', 'geheim');
            self::fail('login should be refused');
        } catch (ImapException $refusal) {
            self::assertStringNotContainsString('geheim', $refusal->getMessage());
            self::assertStringStartsWith('LOGIN refused', $refusal->getMessage());
        }
    }
}
