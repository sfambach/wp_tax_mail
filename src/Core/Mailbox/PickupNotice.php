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
    /** One list for the check here and the server's subject search; a space also matches no space. */
    public const WORDS = ['packstation', 'filiale', 'paket shop', 'parcel shop', 'pick up', 'abhol', 'paketbox'];
    private const CALLED_OFF = '/storn|abgesagt|abgebrochen/iu';

    public static function is(MailMessage $mail): bool
    {
        return preg_match(self::pattern(), $mail->subject) === 1 && preg_match(self::CALLED_OFF, $mail->subject) !== 1;
    }

    /** @return list<string> what to ask the server for; the subject search is a plain substring, so both spellings */
    public static function searchTerms(): array
    {
        $terms = [];

        foreach (self::WORDS as $word) {
            $terms[] = $word;

            if (str_contains($word, ' ')) {
                $terms[] = str_replace(' ', '', $word);
            }
        }

        return $terms;
    }

    private static function pattern(): string
    {
        $words = array_map(static fn (string $word): string => str_replace(' ', '\s?', preg_quote($word, '/')), self::WORDS);

        return '/' . implode('|', $words) . '/iu';
    }
}
