<?php declare(strict_types=1);

namespace TaxMail\Core\Proposal;

use Taxmod\Core\Import\OrderProposal;

/**
 * A won auction or invoice mail names no order number; the Python importer tied it to an order of the same platform
 * by seller or by the start of the item title, within 14 days. Same rule here, so it is not offered as a second order.
 */
final class WonAuctionMatch
{
    private const DAYS = 14;

    public static function isWithoutNumber(OrderProposal $proposal): bool
    {
        return str_starts_with($proposal->orderNumber, 'ohne-Nr:');
    }

    /**
     * @param string|null $merchant the other order's seller
     * @param string|null $orderedDate the other order's date, Y-m-d…
     * @param string $titles the other order's title or item titles, any text
     */
    public static function matches(OrderProposal $won, ?string $merchant, ?string $orderedDate, string $titles): bool
    {
        $sameSeller = $won->counterparty !== null && $merchant !== null && $merchant !== ''
            && mb_strtolower(trim($won->counterparty, ' …')) === mb_strtolower(trim($merchant, ' …'));
        $head = mb_strtolower(mb_substr(rtrim($won->lines[0]->title ?? '', '.… '), 0, 15));
        $sameTitle = $head !== '' && str_contains(mb_strtolower($titles), $head);

        // Orders taken from a shipping mail carry no order date; without one, seller and title must both agree.
        if ($won->orderDate === null || $orderedDate === null || $orderedDate === '') {
            return $sameSeller && $sameTitle;
        }

        $days = abs((int) round((strtotime(substr($orderedDate, 0, 10)) - strtotime(substr($won->orderDate, 0, 10))) / 86400));

        return $days <= self::DAYS && ($sameSeller || $sameTitle);
    }
}
