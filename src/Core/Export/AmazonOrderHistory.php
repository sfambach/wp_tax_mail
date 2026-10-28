<?php declare(strict_types=1);

namespace TaxMail\Core\Export;

use Taxmod\Core\Import\Money;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;

/**
 * Amazon's «Order History.csv» from «Meine Daten anfordern» — the orders from before the mail parser, one proposal per
 * order. A row is one item of one shipment; its «Total Amount» is the item's gross price times the quantity plus the
 * row's shipping charge, so the order total is their sum. Addresses and payment columns are not read. A cancelled
 * order is left out; a closed one counts as delivered, since Amazon writes no delivery date.
 *
 * @see docs/pakete/mail/konzept.md (modeller)
 */
final class AmazonOrderHistory
{
    /** Departments that mean material for the workshop, for the choice «only technical». */
    public const TECHNICAL = [
        'Electronics', 'Personal Computers', 'Tools & Home Improvement', 'Tools & Hardware', 'Werkzeug', 'Camera',
        'Wireless Phone Accessory', 'Office Product', 'Automotive', 'Battery', 'Software Download', 'CD-ROM',
    ];

    private const COLUMNS = ['Order ID', 'Order Date', 'Order Status', 'ASIN', 'Product Name', 'Original Quantity',
        'Unit Price', 'Unit Price Tax', 'Shipping Charge', 'Total Amount', 'Currency', 'Ship Date',
        'Carrier Name & Tracking Number', 'Department'];

    /**
     * @param ?string $since first order date kept, «Y-m-d»; null keeps all
     * @param ?list<string> $departments keep an order only if one of its items is in one of these; null keeps all
     */
    public function __construct(private readonly ?string $since = null, private readonly ?array $departments = null)
    {
    }

    /**
     * @return list<OrderProposal> oldest first
     * @throws \InvalidArgumentException when the text is not that file
     */
    public function read(string $csv): array
    {
        $rows = self::rows($csv);
        $orders = [];

        foreach ($rows as $row) {
            $orders[$row['Order ID']][] = $row;
        }

        $proposals = [];

        foreach ($orders as $number => $items) {
            if ($this->keeps($items)) {
                $proposals[] = self::proposal((string) $number, $items);
            }
        }

        usort($proposals, static fn (OrderProposal $a, OrderProposal $b): int => strcmp((string) $a->orderDate, (string) $b->orderDate));

        return $proposals;
    }

    /** @param non-empty-list<array<string, string>> $items */
    private function keeps(array $items): bool
    {
        if (array_filter($items, static fn (array $item): bool => $item['Order Status'] !== 'Cancelled') === []) {
            return false;
        }

        if ($this->since !== null && substr($items[0]['Order Date'], 0, 10) < $this->since) {
            return false;
        }

        return $this->departments === null
            || array_filter($items, fn (array $item): bool => in_array($item['Department'], $this->departments, true)) !== [];
    }

    /** @param non-empty-list<array<string, string>> $items */
    private static function proposal(string $number, array $items): OrderProposal
    {
        $items = array_values(array_filter($items, static fn (array $item): bool => $item['Order Status'] !== 'Cancelled'));
        $lines = [];
        $shipping = 0;
        $total = 0;
        $shipped = [];
        $tracking = [];

        foreach ($items as $item) {
            $unit = (Money::toUnits(self::amount($item['Unit Price'])) ?? 0) + (Money::toUnits(self::amount($item['Unit Price Tax'])) ?? 0);
            $lines[] = new ProposalLine(
                trim($item['Product Name']),
                (float) max(1, (int) $item['Original Quantity']),
                Money::format($unit),
                self::given($item['ASIN']),
            );
            $shipping += Money::toUnits(self::amount($item['Shipping Charge'])) ?? 0;
            $total += Money::toUnits(self::amount($item['Total Amount'])) ?? 0;

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $item['Ship Date'], $date) === 1) {
                $shipped[] = $date[0];
            }

            // «AMZN_DE(DE5342911425)», «DP(JJD000390016672041856)»; several shipments joined by « and ».
            preg_match_all('/\(([A-Z0-9]{8,})\)/', $item['Carrier Name & Tracking Number'], $numbers);
            array_push($tracking, ...$numbers[1]);
        }

        $open = array_filter($items, static fn (array $item): bool => $item['Order Status'] !== 'Closed') !== [];
        sort($shipped);

        return new OrderProposal(
            'Amazon',
            $number,
            $open ? ProposalKind::Ordered : ProposalKind::Delivered,
            substr($items[0]['Order Date'], 0, 10),
            self::given($items[0]['Currency']) ?? 'EUR',
            $lines,
            Money::format($shipping),
            Money::format($total),
            null,
            null,
            $shipped[0] ?? null,
            array_values(array_unique($tracking)),
            'amazon-export',
            'amazon-export:' . $number,
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private static function rows(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($handle);
        $header = fgetcsv($handle, null, ',', '"', '');

        if (! is_array($header) || array_diff(self::COLUMNS, $header) !== []) {
            fclose($handle);

            throw new \InvalidArgumentException('not Amazon\'s «Order History.csv»: columns missing');
        }

        $rows = [];

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }

            $row = array_intersect_key(array_combine($header, array_map('strval', $values)), array_flip(self::COLUMNS));

            if ($row['Order ID'] !== '') {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private static function amount(string $value): ?string
    {
        return is_numeric($value) ? $value : null;
    }

    private static function given(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || $value === 'Not Available' || $value === 'Not Applicable' ? null : $value;
    }
}
