<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Parser;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Mail\MimeParser;
use TaxMail\Core\Parser\ParserRegistry;
use TaxMail\Core\Parser\ShippedParsers;

/**
 * Each expectation is what the Python importer (legacy/) read from the same synthetic mail.
 */
final class ShopParsersTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function read(string $fixture): array
    {
        $registry = new ParserRegistry(ShippedParsers::all(), array_fill_keys(['ebay', 'reichelt', 'mouser', 'conrad', 'aliexpress', 'jlcpcb', 'amazon'], true));
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.eml'));
        $parser = $registry->parserFor($mail) ?? throw new \RuntimeException('no parser for ' . $fixture);

        return $parser->parse($mail)->toArray();
    }

    public function testEbayConfirmation(): void
    {
        $order = self::read('e1');

        self::assertSame(['Ebay', '27-13610-49402', 'ordered', '6.99', '51.99', 'r-o-r-c'], [$order['platform'], $order['order_number'], $order['kind'], $order['shipping_cost'], $order['total'], $order['counterparty']]);
        self::assertSame([['title' => 'ISA 8-bit Floppy Multi-I/O Controller Zilog', 'quantity' => 1.0, 'unit_price' => '45', 'article_number' => '123456789012']], $order['lines']);
    }

    public function testEbayShippingBringsTracking(): void
    {
        $order = self::read('e2');

        self::assertSame(['shipped', '27-13610-49402', ['00340434610899111']], [$order['kind'], $order['order_number'], $order['tracking']]);
    }

    public function testEbaySale(): void
    {
        $order = self::read('e3');

        self::assertSame(['sold', '11-22222-33333', '5.49', '35.49', 'retrofan42'], [$order['kind'], $order['order_number'], $order['shipping_cost'], $order['total'], $order['counterparty']]);
        self::assertSame([['title' => 'Commodore 64 Netzteil', 'quantity' => 2.0, 'unit_price' => '15', 'article_number' => null]], $order['lines']);
    }

    public function testEbayTwoItemsQuantityAndVat(): void
    {
        $order = self::read('e4');

        self::assertSame(['0', '18.45', '2.95'], [$order['shipping_cost'], $order['total'], $order['fees']]);
        self::assertSame([
            ['title' => 'Z80 CPU', 'quantity' => 3.0, 'unit_price' => '4', 'article_number' => '223344556677'],
            ['title' => '62256 SRAM', 'quantity' => 1.0, 'unit_price' => '3.5', 'article_number' => '998877665544'],
        ], $order['lines']);
    }

    public function testEbayAdvertIsNoReceipt(): void
    {
        self::assertSame('no_receipt', self::read('e5')['kind']);
    }

    public function testEbayMailAboutOwnSaleIsNoPurchase(): void
    {
        $order = self::read('e7');

        self::assertSame(['sold', '24-15238-03470', []], [$order['kind'], $order['order_number'], $order['lines']]);
    }

    public function testEbayInvoiceWithoutOrderNumberGetsThePythonKey(): void
    {
        $order = self::read('e6');

        self::assertSame(['invoice', 'ohne-Nr:334455667788', '24.8', true], [$order['kind'], $order['order_number'], $order['total'], $order['number_missing']]);
    }

    public function testEbayInvoiceQuantityFollowsLineTotal(): void
    {
        $order = self::read('e8');

        self::assertSame([1.0, '16'], [$order['lines'][0]['quantity'], $order['lines'][0]['unit_price']]);
    }

    public function testCancellationOfOwnSaleIsNoPurchase(): void
    {
        foreach (['e9', 'e10'] as $fixture) {
            $order = self::read($fixture);

            self::assertSame(['sold', '24-13651-27152', []], [$order['kind'], $order['order_number'], $order['lines']]);
        }
    }

    public function testReicheltConfirmation(): void
    {
        $order = self::read('r1');

        self::assertSame(['Reichelt', 'I-123456', 'ordered', '5.95', '19.05'], [$order['platform'], $order['order_number'], $order['kind'], $order['shipping_cost'], $order['total']]);
        self::assertSame([
            ['title' => 'LM317 Spannungsregler 1,5A', 'quantity' => 10.0, 'unit_price' => '0.52', 'article_number' => 'LM 317'],
            ['title' => 'Lochrasterplatine 75x100', 'quantity' => 2.0, 'unit_price' => '3.95', 'article_number' => 'H25PR075'],
        ], $order['lines']);
    }

    public function testReicheltShippingInfo(): void
    {
        $order = self::read('r2');

        self::assertSame(['shipped', 'I-123456', ['00340434610895']], [$order['kind'], $order['order_number'], $order['tracking']]);
    }

    public function testMouserConfirmationSkipsTheMakersNumber(): void
    {
        $order = self::read('m1');

        self::assertSame(['12345678', '20', '4.38', '27.43'], [$order['order_number'], $order['shipping_cost'], $order['fees'], $order['total']]);
        self::assertSame([['title' => 'LM317TG Linear Voltage Regulator', 'quantity' => 5.0, 'unit_price' => '0.61', 'article_number' => '595-LM317TG']], $order['lines']);
    }

    public function testConradOrdered(): void
    {
        $order = self::read('c1');

        self::assertSame(['1234567890', '4.95', '20.93'], [$order['order_number'], $order['shipping_cost'], $order['total']]);
        self::assertSame([['title' => 'Lötzinn 100g', 'quantity' => 2.0, 'unit_price' => '7.99', 'article_number' => '123456']], $order['lines']);
    }

    public function testConrad2026LayoutTakesTitleAboveDeliveryAndExactUnitPrice(): void
    {
        $order = self::read('c2');

        self::assertSame(['2020147197', '4.95', '7.57'], [$order['order_number'], $order['shipping_cost'], $order['total']]);
        self::assertSame([
            ['title' => 'Yageo MF0207F220RH Metallschicht-Widerstand 220 Ω 1 St.', 'quantity' => 100.0, 'unit_price' => '0.0162', 'article_number' => '1417626'],
            ['title' => 'Kontakt Chemie 814727 Sprühröhrchen 5 St.', 'quantity' => 10.0, 'unit_price' => '0.1', 'article_number' => '814727'],
        ], $order['lines']);
    }

    public function testAliExpressAwaitingConfirmation(): void
    {
        $order = self::read('a1');

        self::assertSame(['8123456789012345678', '12.34'], [$order['order_number'], $order['total']]);
        self::assertSame(['ESP32 DevKit', 'USB-C Kabel'], array_column($order['lines'], 'title'));
        self::assertSame([3.0, 2.0], array_column($order['lines'], 'quantity'));
    }

    public function testAliExpressShippedCarriesNoOrderDate(): void
    {
        $order = self::read('a2');

        self::assertSame(['8123456789012345678', 'shipped', null, '2024-10-21'], [$order['order_number'], $order['kind'], $order['order_date'], $order['shipped_date']]);
        self::assertSame(['LP00123456789012'], $order['tracking']);
    }

    public function testAmazonConfirmationHoldsEveryOrderWithFullTitles(): void
    {
        $registry = new ParserRegistry(ShippedParsers::all(), ['amazon' => true]);
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/z1.eml'));
        $parser = $registry->parserFor($mail);
        self::assertTrue($parser instanceof \TaxMail\Core\Parser\MultiOrderMailParser);
        $orders = array_map(static fn ($order): array => $order->toArray(), $parser->parseAll($mail));

        self::assertSame(['304-1111111-2222222', '304-3333333-4444444'], array_column($orders, 'order_number'));
        self::assertSame(['ordered', '2026-10-21', '9.98', 'Amazon.de'], [$orders[0]['kind'], $orders[0]['order_date'], $orders[0]['total'], $orders[0]['counterparty']]);
        self::assertSame('4.49', $orders[1]['total']);
        self::assertSame([['title' => 'ESP32 DevKit V1 Entwicklungsboard mit CP2102 | 2 Stück', 'quantity' => 2.0, 'unit_price' => '4.99', 'article_number' => null]], $orders[0]['lines']);
        self::assertSame([['title' => 'USB-C Kabel 1 m', 'quantity' => 1.0, 'unit_price' => '4.49', 'article_number' => null]], $orders[1]['lines']);
    }

    public function testAmazonDeliveryIsAStatusWithoutOrderDate(): void
    {
        $order = self::read('z2');

        self::assertSame(['304-1111111-2222222', 'delivered', null], [$order['order_number'], $order['kind'], $order['order_date']]);
    }

    public function testJlcpcbReviewAndShippingThroughForwarding(): void
    {
        $review = self::read('j1');
        $shipped = self::read('j2');

        self::assertSame(['W202410151234567', 'ordered'], [$review['order_number'], $review['kind']]);
        self::assertSame(['Leiterplatte isa8_multi_io', 'Leiterplatte backplane-rev2'], array_column($review['lines'], 'title'));
        self::assertSame([null, null], array_column($review['lines'], 'quantity'));
        self::assertSame([5.0, 10.0], array_column($shipped['lines'], 'quantity'));
        self::assertSame(['1234567890'], $shipped['tracking']);
    }
}
