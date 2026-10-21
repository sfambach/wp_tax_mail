<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use InvalidArgumentException;
use TaxMail\Core\Mail\MailMessage;

/**
 * All known parsers and which of them run: those the owner switched on. Which module brings a shop's orders and which
 * only its status is the modeller's duty table now (D-953), not a matter of the parsers.
 */
final class ParserRegistry
{
    /** @var array<string, OrderMailParser> */
    private array $parsers = [];

    /**
     * @param iterable<OrderMailParser> $parsers
     * @param array<string, bool> $switchedOn key => on; a parser missing here is off
     */
    public function __construct(iterable $parsers, private readonly array $switchedOn)
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

    public function isActive(string $key): bool
    {
        return isset($this->parsers[$key]) && ($this->switchedOn[$key] ?? false);
    }

    /** The first active parser that recognises the mail; null when none does. */
    public function parserFor(MailMessage $mail): ?OrderMailParser
    {
        foreach ($this->parsers as $key => $parser) {
            if ($this->isActive($key) && $parser->recognises($mail)) {
                return $parser;
            }
        }

        return null;
    }
}
