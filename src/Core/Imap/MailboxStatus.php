<?php declare(strict_types=1);

namespace TaxMail\Core\Imap;

/**
 * What SELECT reports about a folder; UIDVALIDITY tells whether remembered UIDs still mean the same mails.
 */
final class MailboxStatus
{
    public function __construct(
        public readonly int $messageCount,
        public readonly int $uidValidity,
        public readonly int $uidNext,
    ) {
    }
}
