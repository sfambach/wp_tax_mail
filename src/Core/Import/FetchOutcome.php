<?php declare(strict_types=1);

namespace TaxMail\Core\Import;

/**
 * What became of one fetched mail, and so which folder it goes to (concept §3a).
 */
enum FetchOutcome: string
{
    /** A parser read an order from it; it waits on the review page and stays in the inbox. */
    case Proposed = 'open';
    /** No active parser knows the sender. */
    case Unrecognised = 'unrecognised';
    /** A shop mail without an order — advertising. */
    case NoReceipt = 'discarded';
    /** The shop's parser recognised it but could not read it. */
    case Failed = 'failed';
}
