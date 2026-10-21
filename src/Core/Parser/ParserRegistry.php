<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use InvalidArgumentException;
use TaxMail\Core\Mail\MailMessage;

/**
 * All known parsers and which of them run: switched on by the owner and not blocked by another module. A module may
 * also leave a parser running for status only: it then updates orders in the model but never brings a new one (D-950:
 * once tax_ebay reads purchases from the API, eBay mails only say shipped or delivered).
 *
 * ```mermaid
 * flowchart LR
 *   A["all parsers"] --> S{"switched on?"}
 *   S -->|yes| B{"blocked by a module?"}
 *   B -->|no| R["runs"]
 * ```
 */
final class ParserRegistry
{
    /** @var array<string, OrderMailParser> */
    private array $parsers = [];

    /**
     * @param iterable<OrderMailParser> $parsers
     * @param array<string, bool> $switchedOn key => on; a parser missing here is off
     * @param list<string> $blocked keys another module took over
     * @param list<string> $statusOnly keys whose new orders another module brings
     */
    public function __construct(
        iterable $parsers,
        private readonly array $switchedOn,
        private readonly array $blocked,
        private readonly array $statusOnly = [],
    ) {
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

    public function isBlocked(string $key): bool
    {
        return in_array($key, $this->blocked, true);
    }

    public function isStatusOnly(string $key): bool
    {
        return in_array($key, $this->statusOnly, true);
    }

    public function isActive(string $key): bool
    {
        return isset($this->parsers[$key]) && ($this->switchedOn[$key] ?? false) && ! $this->isBlocked($key);
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
