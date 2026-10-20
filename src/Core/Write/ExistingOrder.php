<?php declare(strict_types=1);

namespace TaxMail\Core\Write;

/**
 * An order already in the model, as far as a follow-up mail (shipped, delivered) needs it.
 */
final class ExistingOrder
{
    /** @param list<string> $trackingNumbers */
    public function __construct(
        public readonly int $recordId,
        public readonly array $trackingNumbers = [],
        public readonly ?string $receivedAt = null,
    ) {
    }
}
