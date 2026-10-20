<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Mail;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Mail\MimeParser;

final class MimeParserTest extends TestCase
{
    public function testMultipartWithEncodedHeaderAndBodies(): void
    {
        $raw = "From: =?UTF-8?Q?reichelt_elektronik?= <Shop@Reichelt.de>\r\n"
            . "Subject: =?ISO-8859-1?Q?Bestellbest=E4tigung?= I-123456\r\n"
            . "Date: Tue, 20 Oct 2026 10:15:00 +0200 (CEST)\r\n"
            . "Content-Type: multipart/alternative;\r\n boundary=\"b1\"\r\n\r\n"
            . "preamble\r\n--b1\r\n"
            . "Content-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "Menge: 2 St=FCck\r\n"
            . "--b1\r\n"
            . "Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode('<p>Menge:&nbsp;2</p>')) 
            . "--b1--\r\n";

        $mail = (new MimeParser())->parse(9, $raw);

        self::assertSame('shop@reichelt.de', $mail->fromAddress);
        self::assertSame('reichelt.de', $mail->senderDomain());
        self::assertSame('Bestellbestätigung I-123456', $mail->subject);
        self::assertSame('2026-10-20', $mail->date?->format('Y-m-d'));
        self::assertSame('Menge: 2 Stück', $mail->text);
        self::assertSame('<p>Menge:&nbsp;2</p>', $mail->html);
    }

    public function testHtmlOnlyMailGetsTextFromHtml(): void
    {
        $raw = "From: x@mouser.com\nContent-Type: text/html; charset=utf-8\n\n"
            . "<html><head><style>p{}</style></head><body><table><tr><td>Artikel</td><td>Menge</td></tr>"
            . "<tr><td>LM317</td><td>5</td></tr></table></body></html>";

        $mail = (new MimeParser())->parse(1, $raw);

        self::assertSame("Artikel Menge\nLM317 5", $mail->text);
    }

    public function testAttachmentsAreSkipped(): void
    {
        $raw = "From: a@b.de\nContent-Type: multipart/mixed; boundary=x\n\n--x\nContent-Type: text/plain\n\nHallo\n"
            . "--x\nContent-Type: text/plain\nContent-Disposition: attachment; filename=r.txt\n\nRechnung\n--x--\n";

        self::assertSame('Hallo', (new MimeParser())->parse(1, $raw)->text);
    }
}
