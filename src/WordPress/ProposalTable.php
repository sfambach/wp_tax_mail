<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Import\FetchedMail;
use TaxMail\Core\Import\FetchOutcome;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\WonAuctionMatch;

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

        // A forwarded copy has its own Message-ID but is the same mail: same order, same kind, same original date.
        if ($fetched->proposal !== null && $fetched->proposal->orderNumber !== '' && $mail->date !== null
            && $this->hasSameMail($fetched->proposal->platform, $fetched->proposal->orderNumber, $fetched->proposal->kind->value, $mail->date->setTimezone(wp_timezone())->format('Y-m-d H:i:s'))) {
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

    /** Forgets the row of a mail no parser could read, so a later attempt (parser switched on, parser fixed) can store it. */
    public function forgetUnread(string $messageId): void
    {
        global $wpdb;

        if ($messageId !== '') {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . Schema::table() . ' WHERE message_id = %s AND status IN (%s, %s)',
                mb_substr($messageId, 0, 191),
                FetchOutcome::Unrecognised->value,
                FetchOutcome::Failed->value
            ));
        }
    }

    /** Forgets every row that was not taken over, so those mails count as unseen again; returns how many went. */
    public function forgetUntaken(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table() . ' WHERE status <> %s', self::IMPORTED));
    }

    /**
     * Open rows grouped by order: every mail about one platform and order number is one group, so the review page
     * shows one order once. Rows without an order number stay alone.
     *
     * @return list<non-empty-list<array<string, mixed>>> `mixed` because these are database rows
     */
    public function openGroups(): array
    {
        $groups = [];

        foreach ($this->open() as $row) {
            $number = trim((string) ($row['order_number'] ?? ''));
            $key = $number === '' ? 'row:' . $row['id'] : mb_strtolower(trim((string) $row['platform'])) . '|' . mb_strtolower($number);
            $groups[$key][] = $row;
        }

        // A won auction without order number joins the open order it belongs to, if that came by mail too: an order
        // with a number first, else another mail without one about the same purchase (offer accepted, then invoice).
        foreach (array_keys($groups) as $key) {
            if (! isset($groups[$key]) || count($groups[$key]) !== 1 || ! str_starts_with((string) $groups[$key][0]['order_number'], 'ohne-Nr:')) {
                continue;
            }

            $row = $groups[$key][0];
            $won = OrderProposal::fromArray((array) json_decode((string) $row['proposal'], true));
            $candidates = array_filter($groups, static fn (array $others, string $otherKey): bool => $otherKey !== $key
                && mb_strtolower((string) $others[0]['platform']) === mb_strtolower($won->platform), ARRAY_FILTER_USE_BOTH);
            uksort($candidates, static fn (string $a, string $b): int => [str_contains($a, '|ohne-nr:'), $a] <=> [str_contains($b, '|ohne-nr:'), $b]);

            foreach ($candidates as $otherKey => $others) {
                $other = OrderProposal::fromArray((array) json_decode((string) $others[0]['proposal'], true));
                $titles = implode(' ', array_map(static fn ($line): string => $line->title, $other->lines));

                if (WonAuctionMatch::matches($won, $other->counterparty, $other->orderDate ?? substr((string) $others[0]['received_at'], 0, 10), $titles)) {
                    $groups[$otherKey][] = $row;
                    unset($groups[$key]);
                    break;
                }
            }
        }

        return array_values($groups);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> the open rows among $ids
     */
    public function openRows(array $ids): array
    {
        return array_values(array_filter(array_map(fn (int $id): ?array => $this->find($id), $ids),
            static fn (?array $row): bool => $row !== null && $row['status'] === self::OPEN));
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

    private function hasSameMail(string $platform, string $orderNumber, string $kind, string $receivedAt): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . Schema::table() . ' WHERE platform = %s AND order_number = %s AND kind = %s AND received_at = %s LIMIT 1',
            $platform,
            $orderNumber,
            $kind,
            $receivedAt
        ));
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
