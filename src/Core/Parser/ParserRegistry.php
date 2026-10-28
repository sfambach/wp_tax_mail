<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use InvalidArgumentException;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\OrderMailParser;

/**
 * The mail readers the shop modules registered. A reader runs while its module is active — there is no switch of its
 * own any more (D-1006); which module brings a shop's orders and which only its status is the modeller's duty table.
 */
final class ParserRegistry
{
    /** @var array<string, OrderMailParser> */
    private array $parsers = [];

    /** @param iterable<OrderMailParser> $parsers */
    public function __construct(iterable $parsers)
    {
        foreach ($parsers as $parser) {
            if (isset($this->parsers[$parser->key()])) {
                throw new InvalidArgumentException('two parsers share the key ' . $parser->key());
            }

            $this->parsers[$parser->key()] = $parser;
        }
    }

    /** @return array<string, OrderMailParser> */
    public function all(): array
    {
        return $this->parsers;
    }

    /** The first reader that recognises the mail; null when none does. */
    public function parserFor(MailMessage $mail): ?OrderMailParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->recognises($mail)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * What the readers are, as one string: when it changes — a module came, went or got a new version — the mails in
     * «Nicht erkannt» are read again.
     */
    public function signature(): string
    {
        $parts = array_map(static fn (OrderMailParser $parser): string => $parser->key() . '@' . $parser->version(), $this->parsers);
        sort($parts);

        return implode(',', $parts);
    }
}
