<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Proposal\OrderProposal;

/**
 * One mail connection: recognises the mails of one shop and reads an order proposal from them. The owner switches
 * each one on or off in the settings (D-942).
 */
interface OrderMailParser
{
    /** Stable key for the settings, e.g. «reichelt». */
    public function key(): string;

    /** The shop's name as shown in the settings — a proper name, not translated. */
    public function shopName(): string;

    public function recognises(MailMessage $mail): bool;

    /** Only called after recognises() said yes; throws ParserException when the mail is the shop's but unreadable. */
    public function parse(MailMessage $mail): OrderProposal;
}
