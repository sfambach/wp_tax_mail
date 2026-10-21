<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

/**
 * One sorting rule (D-949): a mail whose sender and subject contain the given text goes to the folder. An empty text
 * matches anything, so a rule may name only the sender or only the subject.
 */
final class SortRule
{
    public function __construct(
        public readonly string $sender,
        public readonly string $subject,
        public readonly string $folder,
    ) {
    }

    public function matches(string $sender, string $subject): bool
    {
        return ($this->sender !== '' || $this->subject !== '')
            && ($this->sender === '' || str_contains(mb_strtolower($sender), mb_strtolower($this->sender)))
            && ($this->subject === '' || str_contains(mb_strtolower($subject), mb_strtolower($this->subject)));
    }
}
