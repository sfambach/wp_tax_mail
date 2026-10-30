<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Label\ShippingLabel;
use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\Core\Import\SecretBox;
use Taxmod\WordPress\Import\Vault;

/**
 * The DHL labels read from mails, handed to the eBay module through the filter `taxmail_dhl_labels`; the eBay module
 * says when one is printed through the action `taxmail_dhl_label_printed` (cart number). A label goes two weeks after
 * printing, or 30 days after the mail if nobody printed it (ShippingLabel). A label link opens the PDF without a login,
 * so the list is stored sealed like a password (his rule 2026-10-21: «keys und passwörter immer verschlüsselt speichern»).
 */
final class ShippingLabels
{
    public const FILTER = 'taxmail_dhl_labels';

    public const PRINTED_ACTION = 'taxmail_dhl_label_printed';

    private const OPTION = 'taxmail_dhl_labels';

    public static function remember(ShippingLabel $label): void
    {
        $kept = self::stored();
        // A mail read again keeps the moment it was printed.
        $printed = array_values(array_filter($kept, static fn (ShippingLabel $old): bool => $old->cart === $label->cart))[0]->printedAt ?? null;
        $others = array_filter($kept, static fn (ShippingLabel $old): bool => $old->cart !== $label->cart);
        self::store([...$others, $printed === null ? $label : $label->printed($printed)]);
    }

    /** The eBay module printed the label of this cart: from now on it is kept two weeks. */
    public static function printed(mixed $cart): void
    {
        $cart = is_scalar($cart) ? (string) $cart : '';
        $now = current_time(DATE_ATOM);
        self::store(array_map(static fn (ShippingLabel $label): ShippingLabel => $label->cart === $cart ? $label->printed($now) : $label, self::stored()));
    }

    /** Drops what has expired — run on every fetch, so nothing waits for the next label mail. */
    public static function purge(): void
    {
        $kept = self::stored();
        $live = array_filter($kept, static fn (ShippingLabel $label): bool => ! $label->expired(time()));

        if (count($live) !== count($kept)) {
            self::store($live);
        }
    }

    /**
     * Newest first; expired ones are left out.
     *
     * @return list<array{cart: string, date: string, links: list<string>, printed_at: ?string}>
     */
    public static function recent(): array
    {
        $labels = array_filter(self::stored(), static fn (ShippingLabel $label): bool => ! $label->expired(time()));
        usort($labels, static fn (ShippingLabel $a, ShippingLabel $b): int => strcmp($b->date, $a->date));

        return array_map(static fn (ShippingLabel $label): array => $label->toArray(), $labels);
    }

    /** @param iterable<ShippingLabel> $labels */
    private static function store(iterable $labels): void
    {
        $live = array_values(array_filter([...$labels], static fn (ShippingLabel $label): bool => ! $label->expired(time())));

        if ($live === []) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, self::box()->seal((string) wp_json_encode(array_map(
            static fn (ShippingLabel $label): array => $label->toArray(),
            $live
        ))), false);
    }

    /**
     * @param list<array<string, mixed>> $labels what other plugins added; `mixed` because a filter can carry anything
     * @return list<array<string, mixed>>
     */
    public static function filter(mixed $labels): array
    {
        return [...(is_array($labels) ? array_values($labels) : []), ...self::recent()];
    }

    /** @return list<ShippingLabel> */
    private static function stored(): array
    {
        $sealed = get_option(self::OPTION, '');

        if (! is_string($sealed) || $sealed === '') {
            return [];
        }

        try {
            $rows = json_decode(self::box()->open($sealed), true);
        } catch (SecretDoesNotOpen) {
            return []; // the salts changed; the next mails fill it again
        }

        return array_values(array_map(
            static fn (array $row): ShippingLabel => ShippingLabel::fromArray($row),
            array_filter(is_array($rows) ? $rows : [], 'is_array')
        ));
    }

    private static function box(): SecretBox
    {
        return Vault::for('taxmail-labels');
    }
}
