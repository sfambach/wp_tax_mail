<?php declare(strict_types=1);

namespace TaxMail\Core\Proposal;

use TaxMail\Core\Parser\LineCollector;

/**
 * Folds the proposals of one order — confirmation, shipping, delivery mail — into one, as the Python importer's Store
 * did across mails: the confirmation's items and amounts win, tracking numbers add up, the rest fills gaps.
 */
final class ProposalMerger
{
    /** @param non-empty-list<OrderProposal> $proposals all of one platform and order number */
    public static function merge(array $proposals): OrderProposal
    {
        // Confirmation first, then by how much each knows, so `first non-empty` picks the best source.
        usort($proposals, static fn (OrderProposal $a, OrderProposal $b): int => [self::rank($a), -count($a->lines)] <=> [self::rank($b), -count($b->lines)]);
        $first = static function (callable $field) use ($proposals): mixed {
            foreach ($proposals as $proposal) {
                $value = $field($proposal);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $priced = array_values(array_filter($proposals, static fn (OrderProposal $p): bool => $p->lines !== []
            && array_filter($p->lines, static fn (ProposalLine $line): bool => $line->unitPrice !== null) !== []));
        $lines = $priced !== [] ? $priced[0]->lines : self::union($proposals);
        $tracking = array_values(array_unique(array_merge(...array_map(static fn (OrderProposal $p): array => $p->trackingNumbers, $proposals))));
        $kinds = array_map(static fn (OrderProposal $p): ProposalKind => $p->kind, $proposals);
        // A won auction's stand-in key gives way to the real order number of a mail it was grouped with.
        $numbered = array_values(array_filter($proposals, static fn (OrderProposal $p): bool => ! WonAuctionMatch::isWithoutNumber($p)));
        $base = $numbered[0] ?? $proposals[0];

        return new OrderProposal(
            $base->platform,
            $base->orderNumber,
            in_array(ProposalKind::Ordered, $kinds, true) ? ProposalKind::Ordered : $base->kind,
            $first(static fn (OrderProposal $p): ?string => $p->kind === ProposalKind::Ordered ? $p->orderDate : null) ?? self::earliest($proposals),
            $priced !== [] ? $priced[0]->currency : $base->currency,
            $lines,
            $first(static fn (OrderProposal $p): ?string => $p->shippingCost),
            $first(static fn (OrderProposal $p): ?string => $p->total),
            $first(static fn (OrderProposal $p): ?string => $p->counterparty),
            $first(static fn (OrderProposal $p): ?string => $p->fees),
            $first(static fn (OrderProposal $p): ?string => $p->shippedDate),
            $tracking,
        );
    }

    private static function rank(OrderProposal $proposal): int
    {
        return match ($proposal->kind) {
            ProposalKind::Ordered  => 0,
            ProposalKind::Invoice  => 1,
            ProposalKind::Shipped  => 2,
            ProposalKind::Delivered => 3,
            default                => 4,
        };
    }

    /** @param list<OrderProposal> $proposals */
    private static function union(array $proposals): array
    {
        $collector = new LineCollector();

        foreach ($proposals as $proposal) {
            foreach ($proposal->lines as $line) {
                $collector->add($line->title, $line->quantity, $line->unitPrice === null ? null : (float) $line->unitPrice, $line->articleNumber);
            }
        }

        return $collector->lines();
    }

    /** @param list<OrderProposal> $proposals */
    private static function earliest(array $proposals): ?string
    {
        $dates = array_values(array_filter(array_map(static fn (OrderProposal $p): ?string => $p->orderDate, $proposals)));
        sort($dates);

        return $dates[0] ?? null;
    }
}
