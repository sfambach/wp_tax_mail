<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Proposal\ProposalKind;
use TaxMail\Core\Write\ExistingOrder;
use WP_Error;

/**
 * «Eingang prüfen»: every open proposal with its items, each item ticked or not; only «Take» writes (D-942, M2, M6).
 * Returns its HTML (CD-8).
 */
final class ReviewScreen
{
    public function __construct(
        private readonly ProposalTable $table,
        private readonly ?ModelGateway $model,
    ) {
    }

    /** @param list<string> $notices already escaped */
    public function render(array $notices): string
    {
        $html = '<div class="wrap"><h1>' . esc_html__('Review incoming orders', 'taxmail') . '</h1>';

        foreach ($notices as $notice) {
            $html .= '<div class="notice notice-info"><p>' . $notice . '</p></div>';
        }

        if ($this->model === null) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('The Taxonomy Modeller is not active — nothing can be taken over.', 'taxmail') . '</p></div>';
        }

        $html .= $this->summary() . $this->fetchButton();
        $rows = $this->table->open();

        if ($rows === []) {
            return $html . '<p>' . esc_html__('Nothing to review.', 'taxmail') . '</p></div>';
        }

        foreach ($rows as $row) {
            $html .= $this->proposal($row);
        }

        return $html . '</div>';
    }

    private function summary(): string
    {
        $counts = $this->table->counts();

        return '<p>' . sprintf(
            /* translators: 1: open, 2: imported, 3: discarded, 4: not recognised or unreadable */
            esc_html__('Open: %1$d · taken: %2$d · discarded: %3$d · not recognised: %4$d', 'taxmail'),
            $counts[ProposalTable::OPEN] ?? 0,
            $counts[ProposalTable::IMPORTED] ?? 0,
            $counts[ProposalTable::DISCARDED] ?? 0,
            ($counts['unrecognised'] ?? 0) + ($counts['failed'] ?? 0)
        ) . '</p>';
    }

    private function fetchButton(): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:1em">'
            . '<input type="hidden" name="action" value="taxmail_fetch">' . wp_nonce_field('taxmail_fetch', '_wpnonce', true, false)
            . get_submit_button(__('Fetch mails now', 'taxmail'), 'secondary', 'submit', false) . '</form>';
    }

    /** @param array<string, mixed> $row */
    private function proposal(array $row): string
    {
        $data = json_decode((string) $row['proposal'], true);
        $data = is_array($data) ? $data : [];
        $kind = ProposalKind::tryFrom((string) ($data['kind'] ?? '')) ?? ProposalKind::Ordered;
        $existing = $this->model?->findOrder((string) ($data['platform'] ?? ''), (string) ($data['order_number'] ?? ''));
        $id = (int) $row['id'];

        $html = '<div class="postbox" style="padding:0 12px 12px;max-width:60em"><h2>'
            . esc_html(sprintf('%s %s', $data['platform'] ?? '', $data['order_number'] ?? ''))
            . ' <small>· ' . esc_html(self::kindLabel($kind)) . '</small></h2>'
            . '<p>' . esc_html((string) $row['subject']) . '<br><small>' . esc_html((string) $row['sender'])
            . ' · ' . esc_html((string) ($row['received_at'] ?? '')) . '</small></p>';

        if ($existing instanceof WP_Error) {
            $html .= '<p class="notice notice-error inline">' . esc_html($existing->get_error_message()) . '</p>';
            $existing = null;
        } elseif ($existing instanceof ExistingOrder) {
            $html .= '<p><strong>' . sprintf(
                /* translators: %d: record id */
                esc_html__('Already in the model: record %d.', 'taxmail'),
                $existing->recordId
            ) . '</strong></p>';
        }

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="proposal" value="' . esc_attr((string) $id) . '">'
            . wp_nonce_field('taxmail_review_' . $id, '_wpnonce', true, false);

        $lines = (array) ($data['lines'] ?? []);
        // A shipping mail with items (JLCPCB, Mouser) can bring an order the model does not have yet.
        $asNewOrder = ! $existing instanceof ExistingOrder && $lines !== []
            && in_array($kind, [ProposalKind::Ordered, ProposalKind::Shipped, ProposalKind::Delivered], true);
        $canTake = $this->model !== null && ($asNewOrder || (
            $existing instanceof ExistingOrder && in_array($kind, [ProposalKind::Shipped, ProposalKind::Delivered], true)
        ));

        if ($asNewOrder) {
            $html .= $this->lines($lines, (string) ($data['currency'] ?? 'EUR'), $canTake);
            $html .= '<p>' . esc_html(sprintf(
                /* translators: 1: shipping, 2: total, 3: currency */
                __('Shipping %1$s · total %2$s %3$s', 'taxmail'),
                $data['shipping_cost'] ?? '–',
                $data['total'] ?? '–',
                $data['currency'] ?? ''
            )) . '</p>';
        } elseif (! $existing instanceof ExistingOrder && $kind !== ProposalKind::Ordered) {
            $html .= '<p>' . esc_html__('The order is not in the model, so there is nothing to add this to.', 'taxmail') . '</p>';
        }

        if (($data['tracking'] ?? []) !== []) {
            /* translators: %s: tracking numbers */
            $html .= '<p>' . esc_html(sprintf(__('Tracking: %s', 'taxmail'), implode(', ', (array) $data['tracking']))) . '</p>';
        }

        if ($canTake) {
            $html .= '<button class="button button-primary" name="action" value="taxmail_take">' . esc_html__('Take', 'taxmail') . '</button> ';
        }

        return $html . '<button class="button" name="action" value="taxmail_discard">' . esc_html__('Discard', 'taxmail') . '</button></form></div>';
    }

    /** @param array<int, mixed> $lines decoded JSON rows */
    private function lines(array $lines, string $currency, bool $enabled): string
    {
        $html = '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__('Items', 'taxmail') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('Take', 'taxmail') . '</th><th scope="col">' . esc_html__('Item', 'taxmail') . '</th>'
            . '<th scope="col">' . esc_html__('Quantity', 'taxmail') . '</th><th scope="col">' . esc_html__('Price each', 'taxmail') . '</th>'
            . '<th scope="col">' . esc_html__('Article no.', 'taxmail') . '</th></tr></thead><tbody>';

        foreach ($lines as $index => $line) {
            $line = (array) $line;
            $html .= '<tr><td><input type="checkbox" name="lines[]" value="' . esc_attr((string) $index) . '" checked'
                . disabled($enabled, false, false) . ' aria-label="' . esc_attr((string) ($line['title'] ?? '')) . '"></td>'
                . '<td>' . esc_html((string) ($line['title'] ?? '')) . '</td>'
                . '<td>' . esc_html(isset($line['quantity']) ? (string) $line['quantity'] : '–') . '</td>'
                . '<td>' . esc_html(isset($line['unit_price']) ? $line['unit_price'] . ' ' . $currency : '–') . '</td>'
                . '<td>' . esc_html((string) ($line['article_number'] ?? '')) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    public static function kindLabel(ProposalKind $kind): string
    {
        return match ($kind) {
            ProposalKind::Ordered   => __('order confirmation', 'taxmail'),
            ProposalKind::Shipped   => __('shipped', 'taxmail'),
            ProposalKind::Delivered => __('delivered', 'taxmail'),
            ProposalKind::Cancelled => __('cancelled', 'taxmail'),
            ProposalKind::Refunded  => __('refunded', 'taxmail'),
            ProposalKind::Invoice   => __('invoice', 'taxmail'),
            ProposalKind::Sold      => __('sold', 'taxmail'),
            ProposalKind::NoReceipt => __('no receipt', 'taxmail'),
            ProposalKind::Other     => __('other', 'taxmail'),
        };
    }
}
