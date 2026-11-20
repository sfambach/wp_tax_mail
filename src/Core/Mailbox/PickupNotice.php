<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use Taxmod\Core\Import\Mail\MailMessage;

/**
 * A notice that a parcel waits to be fetched — Packstation, Filiale, Paketshop. It is still read, but stays in the
 * inbox, since he has to act on it (D-1516). Only the subject decides: order mails name a Packstation in the address
 * too. A subject that calls the pickup off is none.
 */
final class PickupNotice
{
    private const WORDS = '/packstation|postfiliale|filiale|paket\s?shop|parcel\s?shop|pick\s?up|abhol|paketbox/iu';
    private const CALLED_OFF = '/storn|abgesagt|abgebrochen/iu';

    public static function is(MailMessage $mail): bool
    {
        return preg_match(self::WORDS, $mail->subject) === 1 && preg_match(self::CALLED_OFF, $mail->subject) !== 1;
    }
}
