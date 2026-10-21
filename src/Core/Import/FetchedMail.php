<?php declare(strict_types=1);

namespace TaxMail\Core\Import;

use TaxMail\Core\Mail\MailMessage;
use Taxmod\Core\Import\OrderProposal;

/**
 * One mail after the parsers had their go at it.
 */
final class FetchedMail
{
    public function __construct(
        public readonly MailMessage $mail,
        public readonly FetchOutcome $outcome,
        public readonly ?string $parserKey = null,
        public readonly ?OrderProposal $proposal = null,
        public readonly ?string $error = null,
    ) {
    }
}
