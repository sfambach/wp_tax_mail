<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

use RuntimeException;

/**
 * A mail of a known shop whose content the parser could not read — it lands with the unrecognised mails, not lost.
 */
final class ParserException extends RuntimeException
{
}
