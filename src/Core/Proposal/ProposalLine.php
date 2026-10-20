<?php declare(strict_types=1);

namespace TaxMail\Core\Proposal;

/**
 * One item of an order as read from the mail; the owner ticks it or not.
 */
final class ProposalLine
{
    /** @param string|null $unitPrice decimal string, never float — money keeps its digits */
    public function __construct(
        public readonly string $title,
        public readonly float $quantity,
        public readonly ?string $unitPrice = null,
        public readonly ?string $articleNumber = null,
    ) {
    }

    /** @return array{title: string, quantity: float, unit_price: ?string, article_number: ?string} */
    public function toArray(): array
    {
        return [
            'title'          => $this->title,
            'quantity'       => $this->quantity,
            'unit_price'     => $this->unitPrice,
            'article_number' => $this->articleNumber,
        ];
    }

    /** @param array{title: string, quantity: float|int, unit_price?: ?string, article_number?: ?string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['title'], (float) $row['quantity'], $row['unit_price'] ?? null, $row['article_number'] ?? null);
    }
}
