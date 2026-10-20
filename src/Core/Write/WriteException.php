<?php declare(strict_types=1);

namespace TaxMail\Core\Write;

use RuntimeException;

/**
 * A proposal that cannot become a change list — missing configuration or an impossible selection.
 */
final class WriteException extends RuntimeException
{
}
