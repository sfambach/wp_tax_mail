<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * eBay: purchases (confirmation, shipping, delivery, refund), sales, and won auctions or invoices without an order
 * number. Port of legacy/kaeufe-import/kaeufe_import/shops/ebay.py, narrowed to one mail: a checkout mail naming several
 * orders yields the first one only, and a won auction is not matched to an order of other mails. Off while tax_ebay
 * is active (D-942).
 */
final class Ebay implements OrderMailParser
{
    private const ITEM_LABELS = ['Preis:', 'Artikelnr.:', 'Lieferung ca.:'];
    private const ORDER_NUMBER = '/^\d{2}-\d{5}-\d{5}$/';
    private const ADVERT = '/Treffer für|Ergebnis|Only \d+ match|gespeicherten Verkäufer|Rabatt|Gutschein|Coupon|CODE|Wunschartikel|'
        . 'günstiger|Kürzlich angesehen|Schlagen Sie|gehört fast|Konkurrenzgebot|Interesse|eingestellt|Erfahrung|'
        . 'Preisvorschlag gesendet|Ausverkauf|Festival|Passend zu|Boost|Newsletter/iu';

    public function key(): string
    {
        return 'ebay';
    }

    /** «Ebay», as the Python importer wrote it into the model — duplicates are found by this name. */
    public function shopName(): string
    {
        return 'Ebay';
    }

    public function recognises(MailMessage $mail): bool
    {
        $domain = $mail->senderDomain();

        return str_ends_with($domain, 'ebay.com') || str_ends_with($domain, 'ebay.de');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        if (preg_match('/Artikel verkauft|Geld erhalten für/u', $mail->subject) === 1) {
            return $this->sale($mail);
        }

        // Mails about his own sales (a buyer's request, case or return) carry «Bestellnummer:» like a purchase; read as
        // one they would offer his sold item as a new purchase.
        // «Sie haben einen Kauf abgebrochen» is the seller's confirmation of a buyer's cancellation request.
        if (preg_match('/Ihr Käufer|Ein Käufer|Käufer hat|Käufer möchte|an den Käufer|Käufer.*(Anfrage|Rückgabe|Fall)|Ihren Verkauf|Sie haben verkauft|Sie haben einen Kauf abgebrochen/u', $mail->subject) === 1) {
            return $this->aboutOwnSale($mail);
        }

        if (in_array('Bestellnummer:', $mail->lines(), true) && preg_match(self::ADVERT, $mail->subject) !== 1) {
            return $this->purchase($mail);
        }

        return $this->withoutOrderNumber($mail);
    }

    private static function kind(string $subject): ProposalKind
    {
        $subject = mb_strtolower($subject);

        return match (true) {
            str_contains($subject, 'rückerstattung veranlasst')                       => ProposalKind::Refunded,
            str_contains($subject, 'zugestellt') || str_contains($subject, 'geliefert') => ProposalKind::Delivered,
            (bool) preg_match('/zustellung|update zu|versanddienstleister|sendung ist jetzt|verschickt/u', $subject) => ProposalKind::Shipped,
            str_contains($subject, 'bestätigt')                                       => ProposalKind::Ordered,
            default                                                                   => ProposalKind::Other,
        };
    }

    private function purchase(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $kind = self::kind($mail->subject);
        $number = null;
        $seller = null;
        $currency = null;
        $items = new LineCollector();
        $previousEnd = 0;

        foreach ($lines as $i => $line) {
            if ($line !== 'Bestellnummer:' || preg_match(self::ORDER_NUMBER, $lines[$i + 1] ?? '') !== 1) {
                continue;
            }

            $found = $lines[$i + 1];

            if ($number !== null && $found !== $number) {
                break;
            }

            $number = $found;

            // An item's block: title, then price / article no. / delivery, each with value(s) beneath.
            $x = $i;

            while (true) {
                $back = array_values(array_filter([$x - 2, $x - 3], static fn (int $y): bool => $y > $previousEnd
                    && in_array($lines[$y] ?? '', self::ITEM_LABELS, true) && ! in_array($lines[$x - 1] ?? '', self::ITEM_LABELS, true)));

                if ($back === []) {
                    break;
                }

                $x = $back[0];
            }

            $titleAt = $x - 1;

            while ($titleAt > $previousEnd && in_array($lines[$titleAt], ['eBay-Käuferschutz', 'Deals ansehen', 'Bestellung nachverfolgen'], true)) {
                $titleAt--;
            }

            $title = $titleAt >= $previousEnd ? ($lines[$titleAt] ?? null) : null;
            $block = array_slice($lines, $x, $i - $x);
            $article = Text::after($block, 'Artikelnr.:');
            $price = Text::after($block, 'Preis:');
            $quantity = 1.0;
            $unit = null;

            if ($price !== null) {
                if (preg_match('/\((\d+)\s*x\s*(.+?)\)/u', $price, $match) === 1) {
                    $quantity = (float) $match[1];
                    [$unit, $itemCurrency] = Text::money($match[2]);
                } else {
                    [$unit, $itemCurrency] = Text::money($price);
                }

                $currency = $itemCurrency ?? $currency;
            }

            $j = $i + 2;

            while ($j < min(count($lines), $i + 40)) {
                if ($lines[$j] === 'Verkäufer:') {
                    $seller = trim($lines[$j + 1] ?? '', ' …');
                    $j += 2;
                    break;
                }

                $j++;
            }

            $previousEnd = $j;

            if ($title !== null && ! in_array($title, self::ITEM_LABELS, true) && $kind !== ProposalKind::Refunded) {
                $items->add($title, $quantity, $unit, $article);
            }
        }

        if ($number === null) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        [$shipping, $total, $fees, $totalCurrency] = self::totals($lines);
        $tracking = Text::after($lines, 'Sendungsnummer:');

        if ($tracking === null && preg_match('/Sendungsnummer:\s*([A-Z0-9]{8,})/', $mail->text, $match) === 1) {
            $tracking = $match[1];
        }

        $tracking = $tracking !== null && preg_match('/^[A-Z0-9]{8,}$/', $tracking) === 1 ? [$tracking] : [];

        return new OrderProposal($this->shopName(), $number, $kind, $mail->date?->format('Y-m-d'), $totalCurrency ?? $currency ?? 'EUR',
            $items->lines(), Text::decimal($shipping), Text::decimal($total), $seller, Text::decimal($fees),
            $kind === ProposalKind::Shipped ? $mail->date?->format('Y-m-d') : null, $tracking);
    }

    /**
     * Shipping, total, fees and currency from the block under «Gesamtbetrag:».
     *
     * @param list<string> $lines
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?string}
     */
    private static function totals(array $lines): array
    {
        $index = array_search('Gesamtbetrag:', $lines, true);

        if ($index === false) {
            return [null, null, null, null];
        }

        $segment = array_slice($lines, $index + 1);
        $subtotal = null;
        $shipping = null;
        $currency = null;
        $fees = 0.0;
        $discount = 0.0;

        for ($k = 0; $k < count($segment) - 1; $k++) {
            $label = $segment[$k];
            $value = str_replace('−', '-', $segment[$k + 1]);

            if (preg_match('/^(eBay-Käuferschutz|Mit dem|Ihr eBay|E-Mail-)/u', $label) === 1) {
                break;
            }

            [$amount, $amountCurrency] = Text::money($value);

            if (trim($value) === 'Kostenlos') {
                $amount = 0.0;
            }

            if ($amount === null) {
                continue;
            }

            if (str_starts_with(trim($value), '-') && $amount > 0) {
                $amount = -$amount;
            }

            if (str_starts_with($label, 'Zwischensumme')) {
                $subtotal = $amount;
                $currency = $amountCurrency;
            } elseif ($label === 'Versand') {
                $shipping = $amount;
            } elseif (str_starts_with($label, 'Gutscheine')) {
                $discount += $amount;
            } elseif (preg_match('/^(Mehrwertsteuer|Einfuhrabgaben|Import|Zoll)/u', $label) === 1) {
                $fees += $amount;
            }
        }

        if ($subtotal === null) {
            return [null, null, null, null];
        }

        $fees += $discount;

        return [$shipping, round($subtotal + ($shipping ?? 0) + $fees, 2), $fees != 0.0 ? round($fees, 2) : null, $currency];
    }

    private function sale(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $number = Text::after($lines, 'Bestellung:');

        if ($number === null) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $title = trim((string) preg_replace('/^\W*(Artikel verkauft - |Sie haben Ihr Geld erhalten für )/u', '', $mail->subject));
        $quantity = (float) (int) (Text::after($lines, 'Verkaufte Stückzahl:') ?? 1) ?: 1.0;
        [$sold, $currency] = Text::money(Text::after($lines, 'Verkauft:'));
        $shipping = Text::money(Text::after($lines, 'Versand:'))[0];
        $items = new LineCollector();
        $items->add($title, $quantity, $sold === null ? null : round($sold / $quantity, 2), null);

        return new OrderProposal($this->shopName(), $number, ProposalKind::Sold, $mail->date?->format('Y-m-d'), $currency ?? 'EUR',
            $items->lines(), Text::decimal($shipping), Text::decimal($sold === null ? null : round($sold + ($shipping ?? 0), 2)),
            Text::after($lines, 'Käufer:'));
    }

    private function aboutOwnSale(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $index = array_search('Bestellnummer:', $lines, true);
        $number = $index !== false && preg_match(self::ORDER_NUMBER, $lines[$index + 1] ?? '') === 1 ? $lines[$index + 1] : (Text::after($lines, 'Bestellung:') ?? '');

        return new OrderProposal($this->shopName(), $number, $number === '' ? ProposalKind::NoReceipt : ProposalKind::Sold, $mail->date?->format('Y-m-d'));
    }

    /** A won auction or an eBay invoice names no order number; the key is the one the Python importer gave them. */
    private function withoutOrderNumber(MailMessage $mail): OrderProposal
    {
        $subject = $mail->subject;
        $won = str_starts_with($subject, 'Sie haben den Artikel gekauft') || str_starts_with($subject, 'Preisvorschlag akzeptiert')
            || str_starts_with($subject, 'Aktion erforderlich: Bezahlen');

        if ($won) {
            $price = preg_match('/(?:Höchstgebot|Preisvorschlag|Betrag)[^:\n]*:\s*(EUR [\d.,]+)/u', $mail->text, $bid) === 1
                ? Text::money($bid[1])[0]
                : Text::money($subject)[0];
            $seller = preg_match('/Verkäufer:\s*([^,\n]+)/u', $mail->text, $match) === 1 ? trim($match[1]) : null;
            $title = preg_match('/um (.+?) zu erhalten/u', $subject, $match) === 1 || preg_match('/Kauf (?:von|für) (.+)/u', $subject, $match) === 1
                ? $match[1]
                : $subject;
            $title = mb_substr($title, 0, 120);

            return $this->invoice($mail, ($seller ?? '?') . ':' . mb_substr($title, 0, 30), $title, 1.0, $price, null, $seller, null);
        }

        if (str_starts_with($subject, 'Ihre Rechnung für den eBay-Kauf')
            && preg_match('/\n(\d{12})\n(.+)\n(\d+)\n(EUR [\d.,]+)\n(EUR [\d.,]+)/u', "\n" . implode("\n", $mail->lines()), $match) === 1) {
            $price = Text::money($match[4])[0];
            $lineTotal = Text::money($match[5])[0];
            // The number column is not always the quantity; price and line total say how many were bought.
            $quantity = $price !== null && $price > 0 && $lineTotal !== null ? max(1.0, round($lineTotal / $price)) : (float) $match[3];

            return $this->invoice($mail, $match[1], $match[2], $quantity, $price, $match[1], null,
                Text::money(Text::after($mail->lines(), 'Gesamt:'))[0]);
        }

        return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
    }

    private function invoice(MailMessage $mail, string $key, string $title, float $quantity, ?float $price, ?string $article, ?string $seller, ?float $total): OrderProposal
    {
        $items = new LineCollector();
        $items->add($title, $quantity, $price, $article);

        // No order number in these mails: the key only keeps two of them apart; the modeller finds the order by seller and title.
        return new OrderProposal($this->shopName(), 'ohne-Nr:' . $key, ProposalKind::Invoice, $mail->date?->format('Y-m-d'), 'EUR',
            $items->lines(), null, Text::decimal($total), $seller, numberMissing: true);
    }
}
