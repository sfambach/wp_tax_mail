<?php declare(strict_types=1);

namespace TaxMail\Core\Parser\Shop;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Parser\OrderMailParser;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;

/**
 * JLCPCB (reaches the mailbox forwarded): review mail «Order Review … Completed» (the boards of the order), shipping
 * mail «On Its Way» (boards with quantity, carrier, tracking number), «Ready to pick up» (tracking number).
 * No mail names prices. Port of legacy/kaeufe-import/kaeufe_import/shops/jlcpcb.py.
 *
 * The item titles «Leiterplatte …» and «Bestückung (SMT-Auftrag …)» are data written into the model, in the
 * owner's language, as the Python importer wrote them.
 */
final class Jlcpcb implements OrderMailParser
{
    private const ORDER = '/\bW\d{14,16}\b/';
    private const PIECES = '/^(\d+) pcs$/';
    private const SMT = '/^SMT\d{12}$/';

    public function key(): string
    {
        return 'jlcpcb';
    }

    public function shopName(): string
    {
        return 'JLCPCB';
    }

    public function recognises(MailMessage $mail): bool
    {
        return str_contains($mail->senderDomain(), 'jlcpcb');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $subject = $mail->subject;
        $text = implode(' ', $lines);

        if (preg_match(self::ORDER, $subject, $match) !== 1 && preg_match(self::ORDER, $text, $match) !== 1) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        $number = $match[0];

        if (str_contains($subject, 'Review')) {
            $boards = [];
            $start = self::firstIndex($lines, static fn (string $x): bool => str_contains($x, '【'));

            if ($start !== null) {
                for ($k = $start + 1; $k < count($lines) - 1; $k++) {
                    if (in_array($lines[$k + 1], ['Approved', 'Failed', 'Audit Failed'], true) && ! str_starts_with($lines[$k], 'View order')) {
                        $boards = self::addBoard($boards, $lines[$k], null);
                    }
                }
            }

            return new OrderProposal($this->shopName(), $number, ProposalKind::Ordered, $mail->date?->format('Y-m-d'),
                lines: self::toLines($boards), counterparty: 'JLCPCB');
        }

        if (str_contains($subject, 'On Its Way')) {
            $tracking = preg_match('/pick up by (.{0,40}?)\.\s*Tracking Number:\s*(\S+)/', $text, $found) === 1 ? [$found[2]] : [];
            $boards = [];
            $unnamed = [];
            $start = self::firstIndex($lines, static fn (string $x): bool => str_contains($x, 'Items in this shipment'));

            if ($start !== null) {
                for ($k = $start + 1; $k < count($lines); $k++) {
                    if (str_starts_with($lines[$k], 'View order')) {
                        break;
                    }

                    if (preg_match(self::PIECES, $lines[$k], $pieces) !== 1) {
                        continue;
                    }

                    // «N pcs» without a name before it: the assembled boards of the SMT orders, in their order.
                    $previousIsPieces = $k - 1 > $start && preg_match(self::PIECES, $lines[$k - 1]) === 1;

                    if ($k - 1 > $start && ! $previousIsPieces) {
                        $boards = self::addBoard($boards, $lines[$k - 1], (float) $pieces[1]);
                    } else {
                        $unnamed[] = (float) $pieces[1];
                    }
                }
            }

            foreach ($boards as $key => $board) {
                if (preg_match(self::SMT, $board['name']) === 1 && $unnamed !== []) {
                    $boards[$key]['quantity'] = array_shift($unnamed);
                }
            }

            return new OrderProposal($this->shopName(), $number, ProposalKind::Shipped, $mail->date?->format('Y-m-d'),
                lines: self::toLines($boards), counterparty: 'JLCPCB', shippedDate: $mail->date?->format('Y-m-d'), trackingNumbers: $tracking);
        }

        if (str_contains($subject, 'pick up')) {
            $tracking = preg_match('/tracking number is (\w+)\.?\s*\((\w+)\)/', $text, $found) === 1 ? [$found[1]] : [];

            return new OrderProposal($this->shopName(), $number, ProposalKind::Shipped, counterparty: 'JLCPCB', trackingNumbers: $tracking);
        }

        return new OrderProposal($this->shopName(), $number, ProposalKind::Other, counterparty: 'JLCPCB');
    }

    /**
     * Board name without file number (_Y87), «gerber», a bracketed addition and an ellipsis; «-» and «_» alike —
     * to recognise the same board in review and shipping mail.
     */
    private static function normalise(string $name): string
    {
        $n = preg_replace('/(\.{3}|…)$/u', '', trim($name)) ?? $name;
        $n = explode('(', $n)[0];
        $n = preg_replace('/_Y\d+/', '', $n) ?? $n;
        $n = preg_replace('/[_ ]?gerber/i', '', $n) ?? $n;
        $n = strtolower(trim(preg_replace('/[-_ ]+/', '_', $n) ?? $n, '_'));

        return $n !== '' ? $n : strtolower(trim($name));
    }

    private static function title(string $name): string
    {
        if (preg_match(self::SMT, $name) === 1) {
            return sprintf('Bestückung (SMT-Auftrag %s)', $name);
        }

        $t = preg_replace('/(\.{3}|…)$/u', '', trim($name)) ?? $name;
        $t = preg_replace('/\((replacefile|Reorde\w*|-返\w*)\)?$/u', '', $t) ?? $t;
        $t = preg_replace('/_Y\d+/', '', $t) ?? $t;
        $t = trim(preg_replace('/[_ ]?gerber/i', '', $t) ?? $t, ' _-(');

        return $t !== '' ? 'Leiterplatte ' . $t : sprintf('Leiterplatte (Datei %s)', trim($name));
    }

    /**
     * @param list<array{name: string, quantity: ?float}> $boards
     * @return list<array{name: string, quantity: ?float}>
     */
    private static function addBoard(array $boards, string $name, ?float $quantity): array
    {
        $key = self::normalise($name);

        foreach ($boards as $index => $board) {
            $other = self::normalise($board['name']);

            if ($other === $key || str_starts_with($other, $key) || str_starts_with($key, $other)) {
                $boards[$index]['quantity'] = $quantity ?? $board['quantity'];

                if (mb_strlen($name) > mb_strlen($board['name']) && ! str_ends_with($name, '...') && ! str_ends_with($name, '…')) {
                    $boards[$index]['name'] = $name;
                }

                return $boards;
            }
        }

        $boards[] = ['name' => $name, 'quantity' => $quantity];

        return $boards;
    }

    /**
     * @param list<array{name: string, quantity: ?float}> $boards
     * @return list<ProposalLine>
     */
    private static function toLines(array $boards): array
    {
        return array_map(static fn (array $board): ProposalLine => new ProposalLine(self::title($board['name']), $board['quantity']), $boards);
    }

    /**
     * @param list<string> $lines
     * @param callable(string): bool $test
     */
    private static function firstIndex(array $lines, callable $test): ?int
    {
        foreach ($lines as $index => $line) {
            if ($test($line)) {
                return $index;
            }
        }

        return null;
    }
}
