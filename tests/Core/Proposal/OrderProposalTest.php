<?php declare(strict_types=1);

namespace TaxMail\Tests\Core\Proposal;

use PHPUnit\Framework\TestCase;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalKind;
use TaxMail\Core\Proposal\ProposalLine;

final class OrderProposalTest extends TestCase
{
    public function testSurvivesTheTripThroughJson(): void
    {
        $proposal = new OrderProposal('Mouser', '12345678', ProposalKind::Shipped, '2026-10-01', 'EUR',
            [new ProposalLine('LM317T', 3, '0.61', '595-LM317T')], '0.00', '1.83', 'Mouser Electronics', null, '2026-10-02', ['1Z999']);

        $back = OrderProposal::fromArray(json_decode((string) json_encode($proposal->toArray()), true));

        self::assertSame($proposal->toArray(), $back->toArray());
    }
}
