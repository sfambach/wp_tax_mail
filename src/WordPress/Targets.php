<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Write\TargetModel;

/**
 * The ids of «Bestellungen», «Käufe» and their fields on fambach.net, measured 2026-10-20 with taxmod/read-node.
 * Stored in an option so they can be changed without a release; the defaults below are only the starting point.
 */
final class Targets
{
    public const OPTION = 'taxmail_targets';

    private const DEFAULTS = [
        'orders_node'       => 149000110051,
        'purchases_node'    => 149000109585,
        'origin_documented' => 149000108192,
        'amount_field'      => 4234,
        'unit_field'        => 4236,
        'order_fields'      => [
            'title'         => 149000103839,
            'origin'        => 149000108187,
            'origin_note'   => 149000108188,
            'order_number'  => 149000110055,
            'platform'      => 149000110056,
            'merchant'      => 149000110057,
            'ordered_at'    => 149000110058,
            'received_at'   => 149000110059,
            'shipping_cost' => 149000110060,
            'fees'          => 149000110061,
            'total'         => 149000110062,
            'tracking'      => 149000110063,
            'lines'         => 149000110064,
        ],
        'purchase_fields'   => [
            'title'             => 149000103839,
            'origin'            => 149000108187,
            'origin_note'       => 149000108188,
            'quantity'          => 149000109581,
            'article_number'    => 149000110052,
            'unit_price'        => 149000110048,
            'total_price'       => 149000110049,
            'landed_unit_price' => 149000110065,
        ],
        'currencies'        => ['EUR' => 8571, 'USD' => 8573, 'GBP' => 8582, 'AUD' => 149000109994],
        // «Preis je Stück», «Preis gesamt» at «Käufe» are 1..1: their part already stands on a new record.
        'required_parts'    => [149000110048, 149000110049],
    ];

    public static function load(): TargetModel
    {
        $stored = get_option(self::OPTION, []);
        $merged = array_replace_recursive(self::DEFAULTS, is_array($stored) ? $stored : []);
        $ids = static fn (array $map): array => array_map('intval', $map);

        return new TargetModel(
            (int) $merged['orders_node'],
            (int) $merged['purchases_node'],
            (int) $merged['origin_documented'],
            (int) $merged['amount_field'],
            (int) $merged['unit_field'],
            $ids($merged['order_fields']),
            $ids($merged['purchase_fields']),
            $ids($merged['currencies']),
            array_values($ids($merged['required_parts'])),
        );
    }
}
