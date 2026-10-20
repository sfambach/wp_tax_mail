<?php declare(strict_types=1);

namespace TaxMail\Core\Proposal;

/**
 * What a mail says about an order; the kinds the Python importer knew.
 */
enum ProposalKind: string
{
    case Ordered = 'ordered';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Invoice = 'invoice';
    case Sold = 'sold';
    /** About an order, but nothing to write — customer service, an invoice without order number. Discard only. */
    case Other = 'other';
    /** A shop mail without an order in it — advertising, newsletter; goes to the «discarded» folder. */
    case NoReceipt = 'no_receipt';
}
