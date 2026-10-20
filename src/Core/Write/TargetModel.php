<?php declare(strict_types=1);

namespace TaxMail\Core\Write;

/**
 * Where in the modeller an order goes: node ids and relation ids, never field names (no special-casing by label).
 * Relation ids survive the tree rebuild — move_field keeps them (D-941).
 */
final class TargetModel
{
    /**
     * @param array<string, int> $orderFields role => relation id: title, origin, origin_note, order_number, platform,
     *        merchant, ordered_at, received_at, shipping_cost, fees, total, tracking, lines
     * @param array<string, int> $purchaseFields role => relation id: title, origin, origin_note, quantity,
     *        article_number, unit_price, total_price, landed_unit_price
     * @param array<string, int> $currencies ISO code => unit node id
     */
    public function __construct(
        public readonly int $ordersNode,
        public readonly int $purchasesNode,
        public readonly int $originDocumented,
        public readonly int $amountField,
        public readonly int $unitField,
        public readonly array $orderFields,
        public readonly array $purchaseFields,
        public readonly array $currencies,
    ) {
    }

    public function currency(string $isoCode): int
    {
        if (! isset($this->currencies[strtoupper($isoCode)])) {
            throw new WriteException('no unit node for currency ' . $isoCode);
        }

        return $this->currencies[strtoupper($isoCode)];
    }

    public function orderField(string $role): int
    {
        return $this->orderFields[$role] ?? throw new WriteException('order field not configured: ' . $role);
    }

    public function purchaseField(string $role): int
    {
        return $this->purchaseFields[$role] ?? throw new WriteException('purchase field not configured: ' . $role);
    }
}
