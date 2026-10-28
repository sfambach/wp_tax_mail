<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Export;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Export\AmazonOrderHistory;

final class AmazonOrderHistoryTest extends TestCase
{
    private const HEADER = "\u{FEFF}ASIN,\"Billing Address\",\"Carrier Name & Tracking Number\",Currency,Department,\"Order Date\",\"Order ID\",\"Order Status\",\"Original Quantity\",\"Product Name\",\"Ship Date\",\"Shipping Charge\",\"Total Amount\",\"Unit Price\",\"Unit Price Tax\"\n";

    private static function csv(): string
    {
        return self::HEADER
            . "B01,\"Somebody Somewhere 1\",\"AMZN_DE(DE5342911425)\",EUR,Electronics,2026-03-27T10:00:00Z,305-1111111-1111111,Closed,1,\"Vacuum pickup pen\",2026-03-28T08:00:00Z,0,39.95,33.57,6.38\n"
            . "B02,\"Somebody Somewhere 1\",\"AMZN_DE(DE5342911425)\",EUR,Kitchen,2026-03-27T10:00:00Z,305-1111111-1111111,Closed,2,\"Tweezers\",2026-03-28T08:00:00Z,4.99,30.89,10.88,2.07\n"
            . "B03,\"Somebody Somewhere 1\",\"Not Available\",EUR,Grocery,2023-05-11T15:57:00Z,303-2222222-2222222,Closed,1,\"Coffee\",2023-05-12T08:00:00Z,0,9.99,9.34,0.65\n"
            . "B04,\"Somebody Somewhere 1\",\"Not Available\",EUR,Electronics,2024-01-01T15:57:00Z,303-3333333-3333333,Cancelled,1,\"Never came\",\"Not Available\",0,9.99,8.39,1.6\n"
            . "B05,\"Somebody Somewhere 1\",\"Not Available\",EUR,\"Not Available\",2025-02-01T15:57:00Z,304-4444444-4444444,Closed,1,\"HeyNana 10nF Keramik-Kondensator\",2025-02-02T08:00:00Z,0,5.99,5.03,0.96\n"
            . "B06,\"Somebody Somewhere 1\",\"Not Available\",EUR,Taschenbuch,2025-03-01T15:57:00Z,304-5555555-5555555,Closed,1,\"3D-Druck für alle\",2025-03-02T08:00:00Z,0,19.99,18.68,1.31\n";
    }

    public function testOneProposalPerOrderWithGrossPricesAndTotal(): void
    {
        $proposals = (new AmazonOrderHistory())->read(self::csv());

        self::assertSame(['303-2222222-2222222', '304-4444444-4444444', '304-5555555-5555555', '305-1111111-1111111'], array_map(static fn ($p): string => $p->orderNumber, $proposals));
        self::assertSame([
            'platform'       => 'Amazon',
            'order_number'   => '305-1111111-1111111',
            'kind'           => 'shipped',
            'order_date'     => '2026-03-27',
            'currency'       => 'EUR',
            'lines'          => [
                ['title' => 'Vacuum pickup pen', 'quantity' => 1.0, 'unit_price' => '39.95', 'article_number' => 'B01'],
                ['title' => 'Tweezers', 'quantity' => 2.0, 'unit_price' => '12.95', 'article_number' => 'B02'],
            ],
            'shipping_cost'  => '4.99',
            'total'          => '70.84',
            'counterparty'   => null,
            'fees'           => null,
            'shipped_date'   => '2026-03-28',
            'tracking'       => ['DE5342911425'],
            'source'         => 'amazon-export',
            'external_id'    => 'amazon-export:305-1111111-1111111',
            'number_missing' => false,
        ], $proposals[3]->toArray());
    }

    public function testDepartmentsAndDateNarrowTheChoice(): void
    {
        $technical = (new AmazonOrderHistory(null, AmazonOrderHistory::TECHNICAL))->read(self::csv());
        $recent = (new AmazonOrderHistory('2024-01-01'))->read(self::csv());

        // The capacitor has no department but its name; the book on 3D printing is a book.
        self::assertSame(['304-4444444-4444444', '305-1111111-1111111'], array_map(static fn ($p): string => $p->orderNumber, $technical));
        self::assertSame(['304-4444444-4444444', '304-5555555-5555555', '305-1111111-1111111'], array_map(static fn ($p): string => $p->orderNumber, $recent));
    }

    public function testAnotherFileIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AmazonOrderHistory())->read("Order ID,Total\n1,2\n");
    }
}
