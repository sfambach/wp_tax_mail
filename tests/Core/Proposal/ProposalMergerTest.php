<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Proposal;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalKind;
use TaxMail\Core\Proposal\ProposalLine;
use TaxMail\Core\Proposal\ProposalMerger;

final class ProposalMergerTest extends TestCase
{
    public function testConfirmationItemsWinAndTrackingAddsUp(): void
    {
        $shipped = new OrderProposal('Ebay', '13-15309-77264', ProposalKind::Shipped, '2026-10-20', 'EUR',
            [new ProposalLine('Universal Ladegerät', 1.0, null, '157915720994')], null, null, null, null, '2026-10-20', ['DHL123456789']);
        $confirmed = new OrderProposal('Ebay', '13-15309-77264', ProposalKind::Ordered, '2026-10-18', 'EUR',
            [new ProposalLine('Universal Ladegerät Netzteil', 1.0, '14.99', '157915720994')], '0', '14.99', 'seller');

        $merged = ProposalMerger::merge([$shipped, $confirmed]);

        self::assertSame(ProposalKind::Ordered, $merged->kind);
        self::assertSame('2026-10-18', $merged->orderDate);
        self::assertSame('14.99', $merged->lines[0]->unitPrice);
        self::assertSame(['14.99', 'seller', '2026-10-20', ['DHL123456789']], [$merged->total, $merged->counterparty, $merged->shippedDate, $merged->trackingNumbers]);
    }

    public function testWithoutConfirmationTheItemsAreUnited(): void
    {
        $a = new OrderProposal('AliExpress', '1', ProposalKind::Other, '2026-10-20', 'EUR', [new ProposalLine('WCH-LinkE', 1.0)], null, '9.76');
        $b = new OrderProposal('AliExpress', '1', ProposalKind::Other, '2026-10-20', 'EUR', [new ProposalLine('WCH-LinkE', 1.0)], null, '9.76');

        $merged = ProposalMerger::merge([$a, $b]);

        self::assertCount(1, $merged->lines);
        self::assertSame('9.76', $merged->total);
    }
}
