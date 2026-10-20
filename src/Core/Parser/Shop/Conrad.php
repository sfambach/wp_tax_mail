<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\Text;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalKind;

/**
 * Conrad: «Bestellt» mail (items, shipping, sum), shipping and delivery mails, invoices.
 * Port of legacy/kaeufe-import/kaeufe_import/shops/conrad.py. An invoice names only its own number; the Python
 * importer matched it to an order afterwards — here it is shown for discarding.
 */
final class Conrad implements OrderMailParser
{
    private const MERCHANT = 'Conrad Electronic';

    public function key(): string
    {
        return 'conrad';
    }

    public function shopName(): string
    {
        return 'Conrad';
    }

    public function recognises(MailMessage $mail): bool
    {
        return $mail->senderDomain() === 'conrad.de';
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $subject = $mail->subject;
        $text = implode("\n", $lines);

        if (str_contains($subject, 'Rechnung')) {
            $invoice = preg_match('/\((\d{10}),/', $subject, $found) === 1 ? $found[1] : '';

            return new OrderProposal($this->shopName(), $invoice, ProposalKind::Invoice, counterparty: self::MERCHANT);
        }

        if (preg_match('/(\d{10})\s*$/', $subject, $match) !== 1 && preg_match('/Auftrag (\d{10})/', $subject, $match) !== 1
            && preg_match('/Bestellnummer:\n(\d{10})/', $text, $match) !== 1 && preg_match('/Bestellung\n?\s*(\d{10})/', $text, $match) !== 1) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        if (! str_starts_with($subject, 'Bestellt')) {
            $delivered = str_contains($subject, 'geliefert') || str_contains(mb_substr($text, 0, 300), 'zugestellt');

            return new OrderProposal($this->shopName(), $match[1], $delivered ? ProposalKind::Delivered : ProposalKind::Shipped,
                counterparty: self::MERCHANT, shippedDate: $delivered ? null : $mail->date?->format('Y-m-d'));
        }

        $items = new LineCollector();

        foreach ($lines as $k => $line) {
            if (str_starts_with($line, 'Menge:') && $k > 0 && $k + 2 < count($lines) && preg_match('/\d+/', $line, $quantity) === 1) {
                $unit = str_starts_with($lines[$k + 1], 'Einzelpreis') ? Text::money($lines[$k + 1])[0] : null;
                $article = str_starts_with($lines[$k + 2], 'Artikelnummer') && preg_match('/\d+/', $lines[$k + 2], $number) === 1 ? $number[0] : null;
                $items->add($lines[$k - 1], (float) $quantity[0], $unit, $article);
            }
        }

        return new OrderProposal($this->shopName(), $match[1], ProposalKind::Ordered, $mail->date?->format('Y-m-d'), 'EUR', $items->lines(),
            Text::decimal(Text::money(Text::after($lines, 'Versandkosten'))[0]), Text::decimal(Text::money(Text::after($lines, 'Summe'))[0]), self::MERCHANT);
    }
}
