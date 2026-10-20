<?php declare(strict_types=1);

namespace TaxMail\Core\Write;

use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalLine;

/**
 * Turns a reviewed proposal into the change list `taxmod/write-data` takes (D-936): an order record with the picked
 * items as purchase records, all in one act. Same shape the Python importer sent.
 *
 * ```mermaid
 * flowchart LR
 *   O["create order @o"] --> V["values of @o"]
 *   P["create purchase @p0 … per picked item"] --> W["values of @pN"]
 *   W --> L["@o.lines = [@p0 …]"]
 * ```
 *
 * The landed price per piece spreads shipping and fees over all items of the order by their price, picked or not —
 * leaving an item out does not make the others dearer.
 */
final class ChangeListBuilder
{
    private const TITLE_LENGTH = 60;

    public function __construct(private readonly TargetModel $target)
    {
    }

    /**
     * @param list<int> $pickedLines indexes into $proposal->lines
     * @param string $originNote one sentence on where the data came from
     * @return list<array<string, mixed>> `mixed` because values nest by field kind
     */
    public function newOrder(OrderProposal $proposal, array $pickedLines, string $originNote): array
    {
        if ($pickedLines === []) {
            throw new WriteException('no item picked');
        }

        $currency = $this->target->currency($proposal->currency);
        $orderValues = $this->withoutEmpty([
            $this->target->orderField('title')        => $this->orderTitle($proposal, $pickedLines),
            $this->target->orderField('platform')     => $proposal->platform,
            $this->target->orderField('order_number') => $proposal->orderNumber,
            $this->target->orderField('merchant')     => $proposal->counterparty,
            $this->target->orderField('ordered_at')   => $proposal->orderDate,
            $this->target->orderField('origin')       => ['values' => [(string) $this->target->originDocumented]],
            $this->target->orderField('origin_note')  => $originNote,
            $this->target->orderField('shipping_cost') => $this->amount($proposal->shippingCost, $currency),
            $this->target->orderField('fees')         => $this->amount($proposal->fees, $currency),
            $this->target->orderField('total')        => $this->amount($proposal->total, $currency),
            $this->target->orderField('tracking')     => $proposal->trackingNumbers === [] ? null : ['values' => $proposal->trackingNumbers],
        ]);

        $changes = [['op' => 'create_record', 'node' => $this->target->ordersNode, 'ref' => 'o']];
        $landed = $this->landedUnitPrices($proposal);
        $refs = [];

        foreach ($pickedLines as $index) {
            $line = $proposal->lines[$index] ?? throw new WriteException('no item ' . $index);
            $ref = 'p' . $index;
            $refs[] = '@' . $ref;
            $changes[] = ['op' => 'create_record', 'node' => $this->target->purchasesNode, 'ref' => $ref];
            $changes[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $this->purchaseValues($line, $currency, $landed[$index] ?? null, $originNote)];
        }

        $orderValues[(string) $this->target->orderField('lines')] = ['values' => $refs];
        $changes[] = ['op' => 'set_values', 'record' => '@o', 'values' => $orderValues];

        return $changes;
    }

    /** @return list<array<string, mixed>> */
    public function delivered(ExistingOrder $order, string $date): array
    {
        return [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => [
            (string) $this->target->orderField('received_at') => $date,
        ]]];
    }

    /**
     * @param list<string> $trackingNumbers
     * @return list<array<string, mixed>> empty when every number is already there
     */
    public function shipped(ExistingOrder $order, array $trackingNumbers): array
    {
        $merged = array_values(array_unique([...$order->trackingNumbers, ...$trackingNumbers]));

        if ($merged === $order->trackingNumbers) {
            return [];
        }

        return [['op' => 'set_values', 'record' => (string) $order->recordId, 'values' => [
            (string) $this->target->orderField('tracking') => ['values' => $merged],
        ]]];
    }

    /** @return array<string, mixed> */
    private function purchaseValues(ProposalLine $line, int $currency, ?string $landedUnitPrice, string $originNote): array
    {
        $unit = Money::toCents($line->unitPrice);

        return $this->withoutEmpty([
            $this->target->purchaseField('title')             => $line->title,
            $this->target->purchaseField('quantity')          => self::quantity($line->quantity),
            $this->target->purchaseField('article_number')    => $line->articleNumber,
            $this->target->purchaseField('unit_price')        => $this->amount($line->unitPrice, $currency),
            $this->target->purchaseField('total_price')       => $unit === null ? null : $this->amount(Money::fromCents((int) round($unit * $line->quantity)), $currency),
            $this->target->purchaseField('landed_unit_price') => $this->amount($landedUnitPrice, $currency),
            $this->target->purchaseField('origin')            => ['values' => [(string) $this->target->originDocumented]],
            $this->target->purchaseField('origin_note')       => $originNote,
        ]);
    }

    /** @return array<int, string> line index => landed price per piece */
    private function landedUnitPrices(OrderProposal $proposal): array
    {
        $lineTotals = [];

        foreach ($proposal->lines as $index => $line) {
            $unit = Money::toCents($line->unitPrice);

            if ($unit === null || $line->quantity <= 0) {
                return [];
            }

            $lineTotals[$index] = (int) round($unit * $line->quantity);
        }

        $sum = array_sum($lineTotals);
        $extra = (Money::toCents($proposal->shippingCost) ?? 0) + (Money::toCents($proposal->fees) ?? 0);
        $landed = [];

        foreach ($lineTotals as $index => $lineTotal) {
            $share = $sum === 0 ? 0 : $extra * $lineTotal / $sum;
            $landed[$index] = Money::fromCents((int) round(($lineTotal + $share) / $proposal->lines[$index]->quantity));
        }

        return $landed;
    }

    /** @return array{neu: array<string, string>}|null a new unit-value part: amount and currency */
    private function amount(?string $amount, int $currency): ?array
    {
        $cents = Money::toCents($amount);

        return $cents === null ? null : ['neu' => [
            (string) $this->target->amountField => Money::fromCents($cents),
            (string) $this->target->unitField   => (string) $currency,
        ]];
    }

    /** @param list<int> $pickedLines */
    private function orderTitle(OrderProposal $proposal, array $pickedLines): string
    {
        $first = $proposal->lines[$pickedLines[0]]->title ?? '';
        $title = trim($proposal->platform . ' ' . $proposal->orderNumber . ($first === '' ? '' : ' · ' . $first));

        return mb_strlen($title) > self::TITLE_LENGTH ? rtrim(mb_substr($title, 0, self::TITLE_LENGTH - 1)) . '…' : $title;
    }

    private static function quantity(float $quantity): string
    {
        return $quantity == floor($quantity) ? (string) (int) $quantity : (string) $quantity;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        $kept = [];

        foreach ($values as $relation => $value) {
            if ($value !== null && $value !== '') {
                $kept[(string) $relation] = $value;
            }
        }

        return $kept;
    }
}
