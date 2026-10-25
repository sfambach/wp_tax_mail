<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use TaxMail\Core\Mail\MailMessage;
use Taxmod\Core\Import\OrderProposal;

/**
 * A shop whose one mail can confirm several orders — Amazon splits a checkout into orders by seller and delivery date
 * and confirms them together. parse() stays the first order, for whoever only needs one.
 */
interface MultiOrderMailParser extends OrderMailParser
{
    /** @return non-empty-list<OrderProposal> every order the mail names, in the mail's order */
    public function parseAll(MailMessage $mail): array;
}
