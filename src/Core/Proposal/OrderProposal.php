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

    /** @param array<string, mixed> $row what toArray() produced, back from JSON */
    public static function fromArray(array $row): self
    {
        $text = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            (string) ($row['platform'] ?? ''),
            (string) ($row['order_number'] ?? ''),
            ProposalKind::from((string) ($row['kind'] ?? ProposalKind::Ordered->value)),
            $text('order_date'),
            (string) ($row['currency'] ?? 'EUR'),
            array_map(static fn (array $line): ProposalLine => ProposalLine::fromArray($line), array_values((array) ($row['lines'] ?? []))),
            $text('shipping_cost'),
            $text('total'),
            $text('counterparty'),
            $text('fees'),
            $text('shipped_date'),
            array_values(array_map('strval', (array) ($row['tracking'] ?? []))),
        );
    }
}
