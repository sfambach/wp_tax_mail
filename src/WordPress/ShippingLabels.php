<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Label\ShippingLabel;
use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\Core\Import\SecretBox;
use Taxmod\WordPress\Import\Vault;

/**
 * The DHL labels read from mails, kept for 30 days and handed to the eBay module through the filter
 * `taxmail_dhl_labels`. A label link opens the PDF without a login, so the list is stored sealed like a password
 * (his rule 2026-10-21: «keys und passwörter immer verschlüsselt speichern»).
 */
final class ShippingLabels
{
    public const FILTER = 'taxmail_dhl_labels';

    private const OPTION = 'taxmail_dhl_labels';

    private const KEEP_DAYS = 30;

    public static function remember(ShippingLabel $label): void
    {
        $since = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
        $labels = array_filter(self::stored(), static fn (ShippingLabel $kept): bool => $kept->cart !== $label->cart && (int) strtotime($kept->date) >= $since);
        $labels[] = $label;

        update_option(self::OPTION, self::box()->seal((string) wp_json_encode(array_map(
            static fn (ShippingLabel $kept): array => $kept->toArray(),
            array_values($labels)
        ))), false);
    }

    /**
     * Newest first; older than 30 days are left out.
     *
     * @return list<array{cart: string, date: string, links: list<string>}>
     */
    public static function recent(): array
    {
        $since = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
        $labels = array_filter(self::stored(), static fn (ShippingLabel $label): bool => (int) strtotime($label->date) >= $since);
        usort($labels, static fn (ShippingLabel $a, ShippingLabel $b): int => strcmp($b->date, $a->date));

        return array_map(static fn (ShippingLabel $label): array => $label->toArray(), $labels);
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
