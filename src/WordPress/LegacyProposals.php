<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use Taxmod\Core\Import\IncomingProposal;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\WordPress\Import\InboxSchema;
use Taxmod\WordPress\Import\InboxTable;

/**
 * Hands the proposals tax_mail 0.0.x kept in its own table to the modeller's inbox, once (D-953): the open ones move
 * over, so nothing he has not looked at yet is lost. The old table stays untouched as a record.
 */
final class LegacyProposals
{
    private const DONE_OPTION = 'taxmail_moved_to_inbox';

    /** Options of 0.0.x that nothing reads any more; `taxmail_targets` stays, the modeller reads it. */
    private const DEAD_OPTIONS = ['taxmail_backlog', 'taxmail_cron_failure', 'taxmail_schema_version'];

    private const OLD_CRON_HOOK = 'taxmail_fetch_cron';

    public static function moveOnce(): void
    {
        if (get_option(self::DONE_OPTION)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'taxmail_proposals';
        $moved = 0;

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            InboxSchema::upgrade();
            $inbox = new InboxTable();

            foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE status = %s AND proposal IS NOT NULL ORDER BY id ASC', 'open'), ARRAY_A) ?: [] as $row) {
                $incoming = self::incoming($row);

                if ($incoming !== null && ! $inbox->has(MailSource::KEY, (string) $incoming->proposal->externalId)) {
                    $inbox->add(MailSource::KEY, $incoming, $row['received_at'] === null ? null : (string) $row['received_at']);
                    $moved++;
                }
            }
        }

        array_map('delete_option', self::DEAD_OPTIONS);
        wp_clear_scheduled_hook(self::OLD_CRON_HOOK);
        update_option(self::DONE_OPTION, current_time('mysql') . ' — ' . $moved, false);
    }

    /** @param array<string, mixed> $row `mixed` because it is a database row */
    private static function incoming(array $row): ?IncomingProposal
    {
        $stored = json_decode((string) $row['proposal'], true);

        if (! is_array($stored)) {
            return null;
        }

        $messageId = (string) $row['message_id'];
        // 0.0.x marked a proposal without an order number by this prefix only.
        $proposal = OrderProposal::fromArray([
            'source'         => MailSource::KEY,
            'external_id'    => $messageId !== '' ? $messageId : ($stored['external_id'] ?? null),
            'number_missing' => str_starts_with((string) ($stored['order_number'] ?? ''), 'ohne-Nr:'),
        ] + $stored);
        $received = $row['received_at'] === null ? '' : (string) $row['received_at'];

        return new IncomingProposal($proposal, [
            'uid'          => (int) $row['mail_uid'],
            'uid_validity' => (int) $row['uid_validity'],
            'message_id'   => $messageId,
            'sender'       => (string) $row['sender'],
            'subject'      => (string) $row['subject'],
            'received_at'  => $received === '' ? null : $received,
            'parser'       => $row['parser'] === null ? null : (string) $row['parser'],
            /* translators: 1: platform, 2: mail subject, 3: date of the mail */
            'origin'       => sprintf(__('From %1$s mail «%2$s» of %3$s.', 'taxmail'), $proposal->platform, (string) $row['subject'], $received === '' ? '?' : substr($received, 0, 10)),
        ]);
    }
}
