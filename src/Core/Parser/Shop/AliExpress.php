<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * AliExpress: mails about one order number — so far only «auf Bestätigung wird gewartet» (title, quantity, order sum).
 * Port of legacy/kaeufe-import/kaeufe_import/shops/aliexpress.py.
 */
final class AliExpress implements OrderMailParser
{
    public function key(): string
    {
        return 'aliexpress';
    }

    public function shopName(): string
    {
        return 'AliExpress';
    }

    public function recognises(MailMessage $mail): bool
    {
        return str_contains($mail->senderDomain(), 'aliexpress');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();

        if (preg_match('/Bestellung (\d{15,20})/', $mail->subject . ' ' . implode(' ', array_slice($lines, 0, 20)), $match) !== 1) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $items = new LineCollector();

        foreach ($lines as $k => $line) {
            if ($k > 1 && preg_match('/^x(\d+)$/', $line, $quantity) === 1) {
                $title = str_starts_with($lines[$k - 1], '$') ? $lines[$k - 2] : $lines[$k - 1];
                $items->add($title, (float) $quantity[1], null, null);
            }
        }

        $total = Text::after($lines, 'Bestellsumme');
        // «Bestellauftrag bestätigt» is the order confirmation; «auf Bestätigung wird gewartet» only says it is on its way.
        $kind = str_contains($mail->subject, 'bestätigt') ? ProposalKind::Ordered : ProposalKind::Other;

        return new OrderProposal($this->shopName(), $match[1], $kind, $mail->date?->format('Y-m-d'), 'EUR', $items->lines(),
            null, $total === null ? null : Text::decimal(Text::money($total)[0]));
    }
}
