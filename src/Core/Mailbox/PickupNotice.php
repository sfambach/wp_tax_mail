<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use Taxmod\Core\Import\Mail\MailMessage;

/**
 * A parcel waiting to be picked up («Ihre Amazon Sendung liegt in einer Packstation»): he still has to act on it, so it
 * stays in the inbox and is never discarded with the other parcel notices (his word 2026-11-20: «diese nachrichten
 * bitte in der inbox lassen»). His own sorting rules still win.
 */
final class PickupNotice
{
    private const SUBJECT_WORDS = ['packstation', 'filiale', 'paketshop', 'parcelshop', 'abholbereit', 'zur abholung', 'abholen'];

    public static function is(MailMessage $mail): bool
    {
        $subject = mb_strtolower($mail->subject);

        foreach (self::SUBJECT_WORDS as $word) {
            if (str_contains($subject, $word)) {
                return true;
            }
        }

        return false;
    }
}
