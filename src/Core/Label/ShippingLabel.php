<?php declare(strict_types=1);

namespace TaxMail\Core\Label;

/**
 * A postage label bought for one of his sales: the cart it was bought in, when, where its PDF can be fetched, and when
 * it was printed. The link and the PDF behind it carry the buyer's address, so a label is kept only as long as needed
 * (his word 2026-10-30: «nachdem sie gedruckt sind nach 2 wochen gelöscht werden»).
 */
final class ShippingLabel
{
    /** A printed label can still be printed again — a smudged label, a parcel that came back. */
    public const KEEP_AFTER_PRINT_DAYS = 14;

    /** A label nobody printed. */
    public const KEEP_UNPRINTED_DAYS = 30;

    /** @param list<string> $links download links, each one PDF */
    public function __construct(
        public readonly string $cart,
        public readonly string $date,
        public readonly array $links,
        public readonly ?string $printedAt = null,
    ) {
    }

    public function printed(string $at): self
    {
        return new self($this->cart, $this->date, $this->links, $this->printedAt ?? $at);
    }

    public function expired(int $now): bool
    {
        $from = $this->printedAt ?? $this->date;
        $days = $this->printedAt === null ? self::KEEP_UNPRINTED_DAYS : self::KEEP_AFTER_PRINT_DAYS;

        return (int) strtotime($from) < $now - $days * 86400;
    }

    /** @return array{cart: string, date: string, links: list<string>, printed_at: ?string} */
    public function toArray(): array
    {
        return ['cart' => $this->cart, 'date' => $this->date, 'links' => $this->links, 'printed_at' => $this->printedAt];
    }

    /** @param array<string, mixed> $row what toArray() produced; `mixed` because it comes back from storage */
    public static function fromArray(array $row): self
    {
        $printed = isset($row['printed_at']) && is_string($row['printed_at']) && $row['printed_at'] !== '' ? $row['printed_at'] : null;

        return new self((string) ($row['cart'] ?? ''), (string) ($row['date'] ?? ''), array_values(array_map('strval', (array) ($row['links'] ?? []))), $printed);
    }
}
