<?php declare(strict_types=1);

namespace TaxMail\Core\Label;

use TaxMail\Core\Mail\MailMessage;

/**
 * DHL's «Auftragsbestätigung Ihrer Online Frankierung» for a label bought through eBay: no order, but the link to the
 * label PDF, which the eBay module attaches to the parcel of his sale. The invoice PDF attached to the mail is not read.
 */
final class DhlLabelMail
{
    public function read(MailMessage $mail): ?ShippingLabel
    {
        if (! str_ends_with(strtolower($mail->fromAddress), '@dhl.com') || ! str_contains($mail->subject, 'Online Frankierung')) {
            return null;
        }

        if (preg_match('/Warenkorb-Nr\.:\s*([A-Z0-9]{6,})/', $mail->text . ' ' . strip_tags($mail->html), $cart) !== 1
            && preg_match('/Frankierung\s+([A-Z0-9]{6,})\s*$/', $mail->subject, $cart) !== 1) {
            return null;
        }

        preg_match_all('#https://www\.dhl\.de/int-versenden/gw/rest/init\?[^"\'\s<>]+#', $mail->html . ' ' . $mail->text, $found);
        $links = array_values(array_unique(array_filter(
            array_map(static fn (string $link): string => html_entity_decode($link, ENT_QUOTES | ENT_HTML5), $found[0]),
            static fn (string $link): bool => str_contains($link, 'download=letter')
        )));

        return $links === [] ? null : new ShippingLabel($cart[1], $mail->date?->format(DATE_ATOM) ?? '', $links);
    }
}
