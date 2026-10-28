<?php declare(strict_types=1);

namespace TaxMail\Core\Import;

use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\OrderProposal;

/**
 * One mail after the parsers had their go at it. $proposal is the first order, $proposals all of them — more than one
 * only for a mail that confirms several orders.
 */
final class FetchedMail
{
    /** @param list<OrderProposal> $proposals */
    public function __construct(
        public readonly MailMessage $mail,
        public readonly FetchOutcome $outcome,
        public readonly ?string $parserKey = null,
        public readonly ?OrderProposal $proposal = null,
        public readonly ?string $error = null,
        public readonly array $proposals = [],
    ) {
    }
}
