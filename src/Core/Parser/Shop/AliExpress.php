<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * AliExpress: mails about one order number — «Bestellauftrag bestätigt» / «auf Bestätigung wird gewartet» (title, quantity,
 * order sum), «versandt» and «zugestellt» (status, tracking number if the mail names one).
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
        $subject = mb_strtolower($mail->subject);
        // «Bestellauftrag bestätigt» is the order confirmation; «auf Bestätigung wird gewartet» only says it is on its way.
        $kind = match (true) {
            str_contains($subject, 'zugestellt') || str_contains($subject, 'geliefert') => ProposalKind::Delivered,
            str_contains($subject, 'versandt') || str_contains($subject, 'versendet') => ProposalKind::Shipped,
            str_contains($subject, 'bestätigt') => ProposalKind::Ordered,
            default => ProposalKind::Other,
        };
        // A status mail is dated by when it was sent, not when the order was placed; giving that date as the order date
        // made every shipping notice propose a wrong change.
        $orderDate = $kind === ProposalKind::Ordered || $kind === ProposalKind::Other ? $mail->date?->format('Y-m-d') : null;
        $tracking = preg_match('/(?:Sendungsnummer|Tracking-?Nummer|Tracking number)\s*:?\s*([A-Z0-9]{8,})/i', $mail->text, $found) === 1
            ? [$found[1]] : [];

        return new OrderProposal($this->shopName(), $match[1], $kind, $orderDate, 'EUR', $items->lines(),
            null, $total === null ? null : Text::decimal(Text::money($total)[0]),
            shippedDate: $kind === ProposalKind::Shipped ? $mail->date?->format('Y-m-d') : null, trackingNumbers: $tracking);
    }
}
