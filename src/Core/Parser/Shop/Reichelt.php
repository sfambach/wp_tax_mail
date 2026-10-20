<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\Text;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalKind;

/**
 * reichelt: order confirmation (two layouts), shipping info (tracking number), customer service about an order.
 * Port of legacy/kaeufe-import/kaeufe_import/shops/reichelt.py.
 */
final class Reichelt implements OrderMailParser
{
    private const NUMBER = '/Bearbeitungsnummer I-(\d{6})|Bestellung I-(\d{6})|I-Net-Nummer (\d{6})|I-Net Nummer[^\n]*\n[^\n]*\n(\d{6})/';
    private const MERCHANT = 'reichelt elektronik';

    public function key(): string
    {
        return 'reichelt';
    }

    public function shopName(): string
    {
        return 'Reichelt';
    }

    public function recognises(MailMessage $mail): bool
    {
        return str_contains($mail->senderDomain(), 'reichelt');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $text = implode("\n", $lines);

        if (preg_match(self::NUMBER, $mail->subject . "\n" . $text, $match) !== 1) {
            if (preg_match('/Auftrag:?\s*(\d{7})/', $mail->subject, $order) === 1) {
                return new OrderProposal($this->shopName(), 'Auftrag ' . $order[1], ProposalKind::Other, counterparty: self::MERCHANT);
            }

            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $number = 'I-' . current(array_filter(array_slice($match, 1), static fn (string $group): bool => $group !== ''));

        if (str_contains($mail->fromAddress, 'versandinfo')) {
            $tracking = preg_match('/Sendungsnummer lautet (\w+)/', $text, $found) === 1 ? [$found[1]] : [];

            return new OrderProposal($this->shopName(), $number, ProposalKind::Shipped, counterparty: self::MERCHANT,
                shippedDate: $mail->date?->format('Y-m-d'), trackingNumbers: $tracking);
        }

        $items = new LineCollector();
        $shipping = null;
        $total = null;
        $overview = array_search('Bestellübersicht', $lines, true);
        $older = array_search('Ihre bestellten Artikel:', $lines, true);

        if ($overview !== false) {
            // article number / text / quantity / unit price
            $k = $overview + 1;

            while ($k + 3 < count($lines) && $lines[$k] !== 'Zwischensumme') {
                if (preg_match('/^\d+$/', $lines[$k + 2]) === 1 && Text::money($lines[$k + 3])[0] !== null) {
                    $items->add($lines[$k + 1], (float) $lines[$k + 2], Text::money($lines[$k + 3])[0], $lines[$k]);
                    $k += 4;
                } else {
                    $k++;
                }
            }

            $shipping = Text::money(Text::after($lines, 'Versandkosten*'))[0];
            $total = Text::money(Text::after($lines, 'Gesamtpreis'))[0];
        } elseif ($older !== false) {
            // older layout: article number / text / quantity / line total (net below)
            $k = $older + 5;

            while ($k + 3 < count($lines)) {
                if (preg_match('/^\d+$/', $lines[$k + 2]) === 1 && Text::money($lines[$k + 3])[0] !== null) {
                    $quantity = (int) $lines[$k + 2];
                    $lineTotal = (float) Text::money($lines[$k + 3])[0];
                    $items->add($lines[$k + 1], (float) $quantity, $quantity > 0 ? round($lineTotal / $quantity, 4) : null, $lines[$k]);
                    $k += 4;

                    if ($k < count($lines) && str_contains($lines[$k], 'netto')) {
                        $k++;
                    }
                } else {
                    $k++;

                    if (preg_match('/^(Versand|Gesamt|Summe|Zwischensumme)/', $lines[$k - 1]) === 1) {
                        break;
                    }
                }
            }

            foreach ($lines as $index => $line) {
                $next = isset($lines[$index + 1]) ? Text::money($lines[$index + 1])[0] : null;

                if (str_starts_with($line, 'Versand') && $shipping === null && $next !== null) {
                    $shipping = $next;
                }

                if (preg_match('/^(Gesamtbetrag|Gesamtpreis|Gesamtsumme|Summe)/', $line) === 1 && $next !== null) {
                    $total = $next;
                }
            }
        }

        $ordered = preg_match('/Bestellung vom (\d{1,2})\.(\d{1,2})\.(\d{4})/', $mail->subject, $date) === 1
            ? sprintf('%04d-%02d-%02d', $date[3], $date[2], $date[1])
            : $mail->date?->format('Y-m-d');

        return new OrderProposal($this->shopName(), $number, ProposalKind::Ordered, $ordered, 'EUR', $items->lines(),
            Text::decimal($shipping), Text::decimal($total), self::MERCHANT);
    }
}
