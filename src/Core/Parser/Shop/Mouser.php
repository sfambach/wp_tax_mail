<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * Mouser: order confirmation (items, goods value, shipping, VAT) and shipment notice (tracking number).
 * Port of legacy/kaeufe-import/kaeufe_import/shops/mouser.py.
 */
final class Mouser implements OrderMailParser
{
    private const PART = '/^\d{2,3}-[A-Z0-9][A-Z0-9\-.\/+#]*$/';
    private const DATE = '/^[A-Z]{3} \d{1,2}, \d{4}$/';
    private const SUMS = [['Merchandise Total', 'sub'], ['Warenwert', 'sub'], ['Gesamtwarenwert', 'sub'], ['Shipping', 'ship'],
        ['Versand', 'ship'], ['VAT', 'vat'], ['MwSt', 'vat'], ['Mehrwertsteuer', 'vat']];

    public function key(): string
    {
        return 'mouser';
    }

    public function shopName(): string
    {
        return 'Mouser';
    }

    public function recognises(MailMessage $mail): bool
    {
        return str_contains($mail->senderDomain(), 'mouser');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        if (str_contains($mail->senderDomain(), 'enews')) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $lines = $mail->lines();

        if (preg_match('/(?:order|Bestellung|Purchase Order)\s+(\d{8})/', $mail->subject, $match) !== 1
            && preg_match('/(?:Web Order Number|Purchase Order Number|Web-Bestellnummer|Bestellnummer)[^\n]*\n:?\s*(\d{8})/', implode("\n", $lines), $match) !== 1) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $subject = mb_strtolower($mail->subject);
        $kind = match (true) {
            str_contains($subject, 'shipment') || str_contains($subject, 'versand') => ProposalKind::Shipped,
            str_contains($subject, 'cancel')                                        => ProposalKind::Cancelled,
            str_contains($subject, 'confirmation') || str_contains($subject, 'bestätigung') => ProposalKind::Ordered,
            default                                                                 => ProposalKind::Other,
        };

        $items = new LineCollector();
        $previous = '';

        foreach ($lines as $k => $line) {
            // The maker's number under Mouser's (645-566-0206F → 566-0206F) is not a new item.
            if (preg_match(self::PART, $line) === 1 && $k + 4 < count($lines) && ! str_ends_with($previous, '-' . $line)) {
                $previous = $line;
                $rest = array_values(array_filter(array_slice($lines, $k + 1, 11), static fn (string $x): bool => preg_match(self::DATE, $x) !== 1));
                $numbers = array_values(array_filter(array_slice($rest, 2), static fn (string $x): bool => preg_match('/^[\d.,]+$/', $x) === 1));

                if (count($numbers) >= 3) {
                    $items->add(trim(($rest[0] ?? '') . ' ' . ($rest[1] ?? '')), (float) (int) Text::number($numbers[0]), Text::number($numbers[1]), $line);
                }
            }
        }

        $sums = [];

        foreach ($lines as $k => $line) {
            foreach (self::SUMS as [$label, $key]) {
                if (str_starts_with($line, $label) && ! isset($sums[$key])) {
                    foreach (array_slice($lines, $k + 1, 2) as $candidate) {
                        if (preg_match('/^-?[\d.,]+$/', $candidate) === 1) {
                            $sums[$key] = Text::number($candidate);
                            break;
                        }
                    }
                }
            }
        }

        $shipping = null;
        $fees = null;
        $total = null;

        if (isset($sums['sub']) && $kind === ProposalKind::Ordered) {
            $shipping = $sums['ship'] ?? null;
            $fees = $sums['vat'] ?? null;
            $total = round($sums['sub'] + ($sums['ship'] ?? 0) + ($sums['vat'] ?? 0), 2);
        }

        $tracking = Text::after($lines, 'Tracking Number') ?? Text::after($lines, 'Sendungsnummer');

        return new OrderProposal($this->shopName(), $match[1], $kind, $mail->date?->format('Y-m-d'), 'EUR', $items->lines(),
            Text::decimal($shipping), Text::decimal($total), 'Mouser Electronics', Text::decimal($fees),
            $kind === ProposalKind::Shipped ? $mail->date?->format('Y-m-d') : null, $tracking === null ? [] : [$tracking]);
    }
}
