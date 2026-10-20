<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Import\FetchedMail;
use TaxMail\Core\Import\FetchOutcome;

/**
 * Reads and writes the proposal rows. Every query is prepared (CD-6).
 */
final class ProposalTable
{
    public const OPEN = 'open';
    public const IMPORTED = 'imported';
    public const DISCARDED = 'discarded';

    /** Stores one fetched mail; returns its row id, or null when the same Message-ID is already there. */
    public function add(FetchedMail $fetched, int $uidValidity): ?int
    {
        global $wpdb;
        $mail = $fetched->mail;

        if ($mail->messageId !== '' && $this->hasMessage($mail->messageId)) {
            return null;
        }

        $wpdb->insert(Schema::table(), [
            'message_id'   => mb_substr($mail->messageId, 0, 191),
            'mail_uid'     => $mail->uid,
            'uid_validity' => $uidValidity,
            'sender'       => mb_substr($mail->fromAddress, 0, 191),
            'subject'      => $mail->subject,
            'received_at'  => $mail->date?->setTimezone(wp_timezone())->format('Y-m-d H:i:s'),
            'parser'       => $fetched->parserKey,
            'platform'     => $fetched->proposal?->platform,
            'order_number' => $fetched->proposal?->orderNumber,
            'kind'         => $fetched->proposal?->kind->value,
            'status'       => $fetched->outcome->value,
            'proposal'     => $fetched->proposal === null ? null : wp_json_encode($fetched->proposal->toArray()),
            'error'        => $fetched->error,
            'created_at'   => current_time('mysql'),
        ]);

        return (int) $wpdb->insert_id ?: null;
    }

    /** @return list<array<string, mixed>> open rows, oldest mail first; `mixed` because these are database rows */
    public function open(): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Schema::table() . ' WHERE status = %s ORDER BY received_at ASC, id ASC',
            self::OPEN
        ), ARRAY_A) ?: [];
    }

    /** Forgets every row that was not taken over, so those mails count as unseen again; returns how many went. */
    public function forgetUntaken(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table() . ' WHERE status <> %s', self::IMPORTED));
    }

    /** @return array<string, int> status => count */
    public function counts(): array
    {
        global $wpdb;
        $counts = [];

        foreach ($wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . Schema::table() . ' GROUP BY status', ARRAY_A) ?: [] as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, int> $written ref => record id, as write-data answered */
    public function close(int $id, string $status, array $written = []): void
    {
        global $wpdb;

        $wpdb->update(Schema::table(), [
            'status'     => $status,
            'written'    => $written === [] ? null : wp_json_encode($written),
            'handled_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    private function hasMessage(string $messageId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . Schema::table() . ' WHERE message_id = %s LIMIT 1',
            mb_substr($messageId, 0, 191)
        ));
    }

    public static function folderFor(FetchOutcome $outcome): ?string
    {
        return match ($outcome) {
            FetchOutcome::Proposed     => null,
            FetchOutcome::NoReceipt    => 'discarded',
            FetchOutcome::Unrecognised,
            FetchOutcome::Failed       => 'unrecognised',
        };
    }
}
