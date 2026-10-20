<?php declare(strict_types=1);

namespace TaxMail\Core\Proposal;

/**
 * One item of an order as read from the mail; the owner ticks it or not.
 */
final class ProposalLine
{
    /**
     * @param string|null $unitPrice decimal string, never float — money keeps its digits
     * @param float|null $quantity null when the mail does not say (JLCPCB review mails)
     * @param string|null $articleNumber the shop's number for the item: Reichelt/Mouser article number, eBay item id
     */
    public function __construct(
        public readonly string $title,
        public readonly ?float $quantity,
        public readonly ?string $unitPrice = null,
        public readonly ?string $articleNumber = null,
    ) {
    }

    /** @return array{title: string, quantity: ?float, unit_price: ?string, article_number: ?string} */
    public function toArray(): array
    {
        return [
            'title'          => $this->title,
            'quantity'       => $this->quantity,
            'unit_price'     => $this->unitPrice,
            'article_number' => $this->articleNumber,
        ];
    }

    /** @param array{title: string, quantity?: float|int|null, unit_price?: ?string, article_number?: ?string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['title'], isset($row['quantity']) ? (float) $row['quantity'] : null, $row['unit_price'] ?? null, $row['article_number'] ?? null);
    }
}
