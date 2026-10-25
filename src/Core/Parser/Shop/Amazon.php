<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\LineCollector;
use TaxMail\Core\Parser\MultiOrderMailParser;
use TaxMail\Core\Parser\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * Amazon.de (no buyer API): «Bestellt», «Versendet», «In Zustellung», «Zugestellt» / «Geliefert». One confirmation can
 * hold several orders — Amazon splits a checkout by seller and delivery date —, each under its own «Bestellnr.». The
 * text cuts titles short («Zeiss Brillen-Reinigungstücher 20...»); the full title is the product image's alt text.
 * The mails name no tracking number and no shipping cost, and the confirmation's «Summe» reads 0.00 € — the total is
 * the sum of the items then.
 */
final class Amazon implements MultiOrderMailParser
{
    private const ORDER_NUMBER = '/^Bestellnr\.\s*\W*(\d{3}-\d{7}-\d{7})/u';

    public function key(): string
    {
        return 'amazon';
    }

    public function shopName(): string
    {
        return 'Amazon';
    }

    public function recognises(MailMessage $mail): bool
    {
        return in_array($mail->senderDomain(), ['amazon.de', 'amazon.com'], true);
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        return $this->parseAll($mail)[0];
    }

    public function parseAll(MailMessage $mail): array
    {
        $kind = self::kind($mail->subject);
        $titles = self::fullTitles($mail->html);
        /** @var array<string, array{items: LineCollector, sellers: array<string, true>, total: ?string}> $orders */
        $orders = [];
        $current = null;
        $lines = $mail->lines();

        foreach ($lines as $k => $line) {
            if (preg_match(self::ORDER_NUMBER, $line, $number) === 1) {
                $current = $number[1];
                $orders[$current] ??= ['items' => new LineCollector(), 'sellers' => [], 'total' => null];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (str_starts_with($line, 'Verkauf durch ')) {
                $orders[$current]['sellers'][trim(substr($line, strlen('Verkauf durch ')))] = true;
            } elseif (preg_match('/^Menge:\s*(\d+)/', $line, $quantity) === 1 && $k > 0) {
                $title = self::titleAbove($lines, $k);
                $price = self::priceBelow($lines, $k);
                $orders[$current]['items']->add($titles[self::stem($title)] ?? $title, (float) $quantity[1],
                    $price === null ? null : $price / max(1, (int) $quantity[1]), null);
            } elseif ($line === 'Summe' && preg_match('/^([\d.]+)\s*€$/u', $lines[$k + 1] ?? '', $sum) === 1 && (float) $sum[1] > 0) {
                $orders[$current]['total'] = $sum[1];
            }
        }

        if ($orders === []) {
            return [new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt)];
        }

        $proposals = [];

        foreach ($orders as $number => $order) {
            $sellers = array_keys($order['sellers']);
            // The confirmation's «Summe» reads 0.00 €; the items carry their prices, and Amazon.de ships them free or
            // names no shipping cost, so their sum is the order total.
            $total = $order['total'] ?? self::sum($order['items']->lines());
            $proposals[] = new OrderProposal($this->shopName(), (string) $number, $kind,
                $kind === ProposalKind::Ordered ? $mail->date?->format('Y-m-d') : null, 'EUR', $order['items']->lines(),
                // A status mail's «Summe» covers only the parcel, not the order.
                null, $kind === ProposalKind::Ordered ? $total : null, count($sellers) === 1 ? $sellers[0] : null,
                shippedDate: $kind === ProposalKind::Shipped && str_starts_with($mail->subject, 'Versendet') ? $mail->date?->format('Y-m-d') : null);
        }

        return $proposals;
    }

    private static function kind(string $subject): ProposalKind
    {
        return match (true) {
            str_starts_with($subject, 'Bestellt')                                                => ProposalKind::Ordered,
            str_starts_with($subject, 'Versendet') || str_starts_with($subject, 'In Zustellung') => ProposalKind::Shipped,
            str_starts_with($subject, 'Zugestellt') || str_starts_with($subject, 'Geliefert')    => ProposalKind::Delivered,
            str_starts_with($subject, 'Storniert')                                               => ProposalKind::Cancelled,
            default                                                                              => ProposalKind::Other,
        };
    }

    /** The item's title: the line above «Verkauf durch» / «Zustand», or directly above «Menge». */
    private static function titleAbove(array $lines, int $quantityAt): string
    {
        for ($k = $quantityAt - 1; $k >= 0 && $k >= $quantityAt - 3; $k--) {
            if (! str_starts_with($lines[$k], 'Verkauf durch ') && ! str_starts_with($lines[$k], 'Zustand:')) {
                return $lines[$k];
            }
        }

        return $lines[$quantityAt - 1];
    }

    /** «12 95 €» after the quantity: euros and cents in one line, as the mail's text gives them. */
    private static function priceBelow(array $lines, int $quantityAt): ?float
    {
        for ($k = $quantityAt + 1; $k <= $quantityAt + 5 && isset($lines[$k]); $k++) {
            if (preg_match('/^(\d+) (\d{2}) €$/u', $lines[$k], $price) === 1) {
                return (float) ($price[1] . '.' . $price[2]);
            }

            if (str_starts_with($lines[$k], 'Menge:')) {
                break;
            }
        }

        return null;
    }

    /**
     * Full titles from the product images' alt texts, keyed by the start the text shows.
     *
     * @return array<string, string>
     */
    private static function fullTitles(string $html): array
    {
        preg_match_all('/<img[^>]+alt="([^"]{12,})"/', $html, $found);
        $titles = [];

        foreach ($found[1] as $alt) {
            $alt = html_entity_decode($alt, ENT_QUOTES | ENT_HTML5);
            $titles[self::stem($alt)] ??= $alt;
        }

        return $titles;
    }

    /** What a cut title and its full form share: the first 20 characters, without the «...». */
    private static function stem(string $title): string
    {
        return mb_substr(rtrim($title, '.… '), 0, 20);
    }

    /** @param list<\Taxmod\Core\Import\ProposalLine> $lines */
    private static function sum(array $lines): ?string
    {
        $cents = 0;

        foreach ($lines as $line) {
            if ($line->unitPrice === null || $line->quantity === null) {
                return null;
            }

            $cents += (int) round((float) $line->unitPrice * $line->quantity * 100);
        }

        return $lines === [] ? null : Text::decimal($cents / 100);
    }
}
