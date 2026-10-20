<?php declare(strict_types=1);

namespace TaxMail\Core\Mail;

use DateTimeImmutable;

/**
 * One mail as a parser sees it: sender, subject, date and the readable body, already decoded.
 */
final class MailMessage
{
    public function __construct(
        public readonly int $uid,
        public readonly string $fromAddress,
        public readonly string $subject,
        public readonly ?DateTimeImmutable $date,
        public readonly string $text,
        public readonly string $html,
    ) {
    }

    /** The part after «@», lower case — shops are recognised by it, as the Python importer did. */
    public function senderDomain(): string
    {
        $at = strrpos($this->fromAddress, '@');

        return $at === false ? '' : strtolower(substr($this->fromAddress, $at + 1));
    }
}
