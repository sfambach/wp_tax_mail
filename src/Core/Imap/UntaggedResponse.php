<?php declare(strict_types=1);

namespace TaxMail\Core\Imap;

/**
 * One «* …» answer line, with any `{n}` literals taken out and kept in order.
 */
final class UntaggedResponse
{
    /** @param list<string> $literals */
    public function __construct(
        public readonly string $text,
        public readonly array $literals,
    ) {
    }
}
