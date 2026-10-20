<?php declare(strict_types=1);

namespace TaxMail\Core\Imap;

use RuntimeException;

/**
 * Anything the mail server refused or the connection lost; the boundary turns it into a WP_Error (CD-10).
 */
final class ImapException extends RuntimeException
{
}
