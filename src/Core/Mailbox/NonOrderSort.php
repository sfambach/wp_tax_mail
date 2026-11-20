<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use Taxmod\Core\Import\Mail\MailMessage;

/**
 * Where a mail goes that does not become an order: his rules first (D-949), then a pickup notice stays in the inbox
 * (D-1516), then what is no receipt — advertising, his own sales, a carrier's notice — is discarded. Null leaves the
 * mail in the inbox.
 */
final class NonOrderSort
{
    public function __construct(private readonly SortRules $rules, private readonly FolderPlan $folders)
    {
    }

    public function target(MailMessage $mail, bool $noReceipt, ?string $default): ?string
    {
        $ruled = $this->rules->folderFor($mail->fromAddress, $mail->subject);

        return match (true) {
            $ruled !== null          => SortRules::resolve($ruled, $this->folders),
            PickupNotice::is($mail)  => null,
            $noReceipt               => $this->folders->discarded,
            default                  => $default,
        };
    }
}
