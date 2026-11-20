<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Label\ShippingLabel;
use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\Core\Import\SecretBox;
use Taxmod\WordPress\Import\Vault;

/**
 * The postage labels read from mails (D-1511: every carrier's), handed to the eBay and shipping modules through the filter
 * `taxmail_shipping_labels` (the older `taxmail_dhl_labels` answers the same); a module says when one is printed through the action
 * `taxmail_shipping_label_printed` or the older `taxmail_dhl_label_printed` (cart number). The PDF behind a stored link is
 * fetched through `taxmail_shipping_label_pdf`, for stored links only. A label goes two weeks after
 * printing, or 30 days after the mail if nobody printed it (ShippingLabel). A label link opens the PDF without a login,
 * so the list is stored sealed like a password (his rule 2026-10-21: «keys und passwörter immer verschlüsselt speichern»).
 */
final class ShippingLabels
{
    public const FILTER = 'taxmail_shipping_labels';

    /** Before D-1511; kept so a module not yet moved keeps its labels. */
    public const DHL_FILTER = 'taxmail_dhl_labels';

    public const PRINTED_ACTION = 'taxmail_shipping_label_printed';

    public const DHL_PRINTED_ACTION = 'taxmail_dhl_label_printed';

    public const PDF_FILTER = 'taxmail_shipping_label_pdf';

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

    /**
     * The PDF behind a link of a stored label, or null — never a link nobody stored, so this fetches nothing else.
     *
     * @param mixed $pdf what an earlier filter answered; `mixed`: a filter carries anything
     */
    public static function pdf(mixed $pdf, mixed $link = ''): ?string
    {
        if (is_string($pdf) && $pdf !== '') {
            return $pdf;
        }

        $stored = array_merge([], ...array_column(self::recent(), 'links'));

        if (! is_string($link) || ! in_array($link, $stored, true)) {
            return null;
        }

        $response = wp_remote_get($link, ['timeout' => 20, 'redirection' => 0]);
        $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);

        return wp_remote_retrieve_response_code($response) === 200 && str_starts_with($body, '%PDF') ? $body : null;
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
