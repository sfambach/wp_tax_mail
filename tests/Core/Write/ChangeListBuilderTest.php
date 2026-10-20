<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Write;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalKind;
use TaxMail\Core\Proposal\ProposalLine;
use TaxMail\Core\Write\ChangeListBuilder;
use TaxMail\Core\Write\ExistingOrder;
use TaxMail\Core\Write\Money;
use TaxMail\Core\Write\TargetModel;
use TaxMail\Core\Write\WriteException;

final class ChangeListBuilderTest extends TestCase
{
    public function testPickedItemsOnlyAndLandedPriceOverAllItems(): void
    {
        $proposal = new OrderProposal('Reichelt', 'I-123456', ProposalKind::Ordered, '2026-10-20', 'EUR', [
            new ProposalLine('LM317', 10, '0.50', 'LM 317'),
            new ProposalLine('Lochraster', 1, '5.00', 'H25PR'),
        ], shippingCost: '5.95', total: '16.95', counterparty: 'reichelt elektronik');

        $changes = (new ChangeListBuilder(self::target()))->newOrder($proposal, [0], 'Aus Mail.');

        self::assertCount(4, $changes);
        self::assertSame(['op' => 'create_record', 'node' => 10, 'ref' => 'o'], $changes[0]);
        self::assertSame(['op' => 'create_record', 'node' => 20, 'ref' => 'p0'], $changes[1]);

        $purchase = $changes[2]['values'];
        self::assertSame('LM317', $purchase['201']);
        self::assertSame('10', $purchase['203']);
        self::assertSame(['neu' => ['4234' => '0.50', '4236' => '8571']], $purchase['205']);
        self::assertSame(['neu' => ['4234' => '5.00', '4236' => '8571']], $purchase['206']);
        // 5.95 shipping, half of the goods value is this item: (5.00 + 2.975) / 10 = 0.7975 → 0.80
        self::assertSame(['neu' => ['4234' => '0.80', '4236' => '8571']], $purchase['207']);

        $order = $changes[3]['values'];
        self::assertSame('@o', $changes[3]['record']);
        self::assertSame('Reichelt I-123456 · LM317', $order['101']);
        self::assertSame(['values' => ['@p0']], $order['113']);
        self::assertSame(['values' => ['99']], $order['102']);
        self::assertSame(['neu' => ['4234' => '16.95', '4236' => '8571']], $order['111']);
        self::assertFalse(isset($order['110']));
    }

    public function testNothingPickedIsRefused(): void
    {
        $this->expectException(WriteException::class);
        (new ChangeListBuilder(self::target()))->newOrder(new OrderProposal('X', '1', ProposalKind::Ordered), [], '');
    }

    public function testShippedAddsOnlyNewTrackingNumbers(): void
    {
        $builder = new ChangeListBuilder(self::target());

        self::assertSame([], $builder->shipped(new ExistingOrder(5, ['A1']), ['A1']));
        self::assertSame(
            [['op' => 'set_values', 'record' => '5', 'values' => ['112' => ['values' => ['A1', 'B2']]]]],
            $builder->shipped(new ExistingOrder(5, ['A1']), ['B2'])
        );
    }

    public function testMoneyRoundTrip(): void
    {
        self::assertSame(1234, Money::toCents('12.34'));
        self::assertSame(1230, Money::toCents('12.3'));
        self::assertSame('-0.05', Money::fromCents(-5));
    }

    private static function target(): TargetModel
    {
        return new TargetModel(10, 20, 99, 4234, 4236, [
            'title' => 101, 'origin' => 102, 'origin_note' => 103, 'order_number' => 104, 'platform' => 105,
            'merchant' => 106, 'ordered_at' => 107, 'received_at' => 108, 'shipping_cost' => 109, 'fees' => 110,
            'total' => 111, 'tracking' => 112, 'lines' => 113,
        ], [
            'title' => 201, 'origin' => 202, 'quantity' => 203, 'article_number' => 204, 'unit_price' => 205,
            'total_price' => 206, 'landed_unit_price' => 207, 'origin_note' => 208,
        ], ['EUR' => 8571]);
    }
}
