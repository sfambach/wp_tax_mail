<?php declare(strict_types=1);

namespace TaxMail\Core\Proposal;

/**
 * What one mail says about one order — the same shape for every shop, and meant to be shared with tax_ebay so
 * one review page can show both (D-942).
 */
final class OrderProposal
{
    /**
     * @param list<ProposalLine> $lines
     * @param string|null $shippingCost decimal string
     * @param string|null $total decimal string
     * @param string|null $fees decimal string — platform fees on a sale
     * @param list<string> $trackingNumbers
     */
    public function __construct(
        public readonly string $platform,
        public readonly string $orderNumber,
        public readonly ProposalKind $kind,
        public readonly ?string $orderDate = null,
        public readonly string $currency = 'EUR',
        public readonly array $lines = [],
        public readonly ?string $shippingCost = null,
        public readonly ?string $total = null,
        public readonly ?string $counterparty = null,
        public readonly ?string $fees = null,
        public readonly ?string $shippedDate = null,
        public readonly array $trackingNumbers = [],
    ) {
    }

    /** @return array<string, mixed> JSON-ready; `mixed` because the lines nest */
    public function toArray(): array
    {
        return [
            'platform'      => $this->platform,
            'order_number'  => $this->orderNumber,
            'kind'          => $this->kind->value,
            'order_date'    => $this->orderDate,
            'currency'      => $this->currency,
            'lines'         => array_map(static fn (ProposalLine $line): array => $line->toArray(), $this->lines),
            'shipping_cost' => $this->shippingCost,
            'total'         => $this->total,
            'counterparty'  => $this->counterparty,
            'fees'          => $this->fees,
            'shipped_date'  => $this->shippedDate,
            'tracking'      => $this->trackingNumbers,
        ];
    }
}
