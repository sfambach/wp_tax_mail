<?php declare(strict_types=1);

namespace TaxMail\Core\Label;

/** A postage label bought for one of his sales: the cart it was bought in, when, and where its PDF can be fetched. */
final class ShippingLabel
{
    /** @param list<string> $links download links, each one PDF */
    public function __construct(
        public readonly string $cart,
        public readonly string $date,
        public readonly array $links,
    ) {
    }

    /** @return array{cart: string, date: string, links: list<string>} */
    public function toArray(): array
    {
        return ['cart' => $this->cart, 'date' => $this->date, 'links' => $this->links];
    }

    /** @param array<string, mixed> $row what toArray() produced; `mixed` because it comes back from storage */
    public static function fromArray(array $row): self
    {
        return new self((string) ($row['cart'] ?? ''), (string) ($row['date'] ?? ''), array_values(array_map('strval', (array) ($row['links'] ?? []))));
    }
}
