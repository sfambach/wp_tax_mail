<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use TaxMail\Core\Mail\MailMessage;

/**
 * A parcel service's own notice («Ihre Sendung kommt heute»): no order number, only a tracking number, and the shop's
 * mails carry the same status. It is sorted to «Verworfen» instead of waiting in «Nicht erkannt» (his word 2026-10-28:
 * «alle nicht erkannt»). A DHL label mail is read before this and never reaches it.
 */
final class CarrierNotice
{
    private const DOMAINS = [
        'dhl.de', 'dhl.com', 'deutschepost.de', 'dpd.de', 'dpd.com', 'myhermes.de', 'hermesworld.com',
        'gls-pakete.de', 'gls-group.eu', 'gls-group.com', 'ups.com', 'fedex.com',
    ];

    public static function is(MailMessage $mail): bool
    {
        $domain = $mail->senderDomain();

        foreach (self::DOMAINS as $carrier) {
            if ($domain === $carrier || str_ends_with($domain, '.' . $carrier)) {
                return true;
            }
        }

        return false;
    }
}
