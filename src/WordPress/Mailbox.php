<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Import\FetchOutcome;
use TaxMail\Core\Import\MailFetcher;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Proposal\ProposalKind;
use TaxMail\Core\Proposal\WonAuctionMatch;
use TaxMail\Core\Write\ExistingOrder;

/**
 * Fetching and sorting the purchasing mailbox. Stores first, moves after — a mail that was not stored stays in the
 * inbox and comes again on the next run.
 *
 * ```mermaid
 * flowchart LR
 *   F["fetch new UIDs"] --> S["store row"]
 *   S -->|open| I["stays in inbox"]
 *   S -->|advertising| D["→ Verworfen"]
 *   S -->|unknown / unreadable| N["→ Nicht erkannt"]
 * ```
 */
final class Mailbox
{
    public const STATE_OPTION = 'taxmail_state';

    /** How far the backlog sort got: {uid_validity, cursor}. */
    public const BACKLOG_OPTION = 'taxmail_backlog';

    /** Seconds one backlog click may run — Strato cuts long requests; the next click carries on. */
    private const BACKLOG_SECONDS = 20;
    public const LOCK = 'taxmail_fetch_lock';

    /** Mails per run — keeps a run inside Strato's time limits; the rest comes with the next run or the button. */
    private const LIMIT = 60;

    public function __construct(
        private readonly Settings $settings,
        private readonly ProposalTable $table = new ProposalTable(),
    ) {
    }

    /**
     * @return array{fetched: int, stored: int, moved: int}
     * @throws ImapException
     */
    public function fetch(): array
    {
        if (get_transient(self::LOCK)) {
            throw new ImapException('another fetch is running');
        }

        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);

        try {
            return $this->fetchLocked();
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /**
     * Moves the mail of a handled row out of the inbox; a mail that is gone already is not an error.
     *
     * @param array<string, mixed> $row a proposal row
     */
    public function moveHandled(array $row, string $folderKey): void
    {
        $client = $this->connect();

        try {
            $status = $client->select($this->settings->folders->inbox);
            $uid = (int) $row['uid_validity'] === $status->uidValidity
                ? (int) $row['mail_uid']
                : ($row['message_id'] !== '' ? $client->uidByMessageId((string) $row['message_id']) : null);

            if ($uid !== null && $client->canMove()) {
                $client->move($uid, FolderPlan::onServer($this->folder($folderKey), $client->delimiter()));
            }
        } finally {
            $client->logout();
        }
    }

    /**
     * Starts over from now: mails the fetch sorted away on its own («Nicht erkannt», «Verworfen») go back to the inbox,
     * proposals not taken over are forgotten, and only mails arriving after this moment are read. Taken-over orders
     * and the «Importiert» folder stay as they are. Written after the first fetch on fambach.net began with the
     * oldest of 3927 mails (2026-10-20).
     *
     * @return array{returned: int, forgotten: int}
     * @throws ImapException
     */
    public function restartFromNow(): array
    {
        if (get_transient(self::LOCK)) {
            throw new ImapException('another fetch is running');
        }

        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);
        $client = $this->connect();

        try {
            $returned = 0;
            $folders = $client->folders();
            $delimiter = $client->delimiter();

            foreach (['unrecognised', 'discarded'] as $key) {
                $folder = FolderPlan::onServer($this->folder($key), $delimiter);

                if (! in_array($folder, $folders, true)) {
                    continue;
                }

                $client->select($folder);

                foreach ($client->uidsAfter(0) as $uid) {
                    $client->move($uid, $this->settings->folders->inbox);
                    $returned++;
                }
            }

            $this->startAt($client);

            return ['returned' => $returned, 'forgotten' => $this->table->forgetUntaken()];
        } finally {
            $client->logout();
            delete_transient(self::LOCK);
        }
    }

    /**
     * Reads the backlog — the mails before the starting point — a slice per call: a mail whose order is in the model
     * already goes to «Importiert», a mail a parser reads otherwise becomes a proposal, everything else stays in the
     * inbox untouched (his word 2026-10-20: «alle mail nochmal parsen lassen und doppelte aussortieren»).
     *
     * @return array{checked: int, moved: int, stored: int, remaining: int, failed: int, first_failure: ?string}
     * @throws ImapException
     */
    public function sortBacklog(ModelGateway $model): array
    {
        if (get_transient(self::LOCK)) {
            throw new ImapException('another fetch is running');
        }

        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);
        $client = $this->connect();

        try {
            $status = $client->select($this->settings->folders->inbox);
            $state = get_option(self::STATE_OPTION, []);

            if (! is_array($state) || (int) ($state['uid_validity'] ?? 0) !== $status->uidValidity) {
                throw new ImapException('no starting point yet — fetch once first');
            }

            $backlog = get_option(self::BACKLOG_OPTION, []);
            // Mode 2 also stores proposals; a cursor from the move-only first version starts over so nothing is skipped.
            $cursor = is_array($backlog) && (int) ($backlog['uid_validity'] ?? 0) === $status->uidValidity && (int) ($backlog['mode'] ?? 1) === 2
                ? (int) ($backlog['cursor'] ?? 0)
                : 0;
            // A mail that ended the last run without a word (memory, time limit) is still marked as being tried: skip it,
            // or every click would die on the same mail.
            $crashed = is_array($backlog) && (int) ($backlog['trying'] ?? 0) > $cursor ? (int) $backlog['trying'] : null;
            $cursor = $crashed ?? $cursor;
            $end = (int) $state['last_uid'];
            $pending = array_values(array_filter($client->uidsAfter($cursor), static fn (int $uid): bool => $uid <= $end));
            $fetcher = new MailFetcher(Plugin::registry($this->settings));
            $target = FolderPlan::onServer($this->folder('imported'), $client->delimiter());
            $started = microtime(true);
            $checked = 0;
            $moved = 0;
            $stored = 0;
            $failed = $crashed === null ? 0 : 1;
            $firstFailure = $crashed === null ? null : 'UID ' . $crashed . ': the last run stopped on this mail';
            $previous = $cursor;

            foreach ($pending as $uid) {
                if (microtime(true) - $started > self::BACKLOG_SECONDS) {
                    break;
                }

                update_option(self::BACKLOG_OPTION, ['uid_validity' => $status->uidValidity, 'cursor' => $previous, 'mode' => 2, 'trying' => $uid], false);

                try {
                    $result = $fetcher->fetchOne($client, $uid);
                } catch (ImapException $failure) {
                    throw $failure;
                } catch (\Throwable $failure) {
                    // One mail nobody planned for must not stop the backlog at the same place on every click.
                    $result = null;
                    $firstFailure ??= 'UID ' . $uid . ': ' . $failure->getMessage();
                }

                if ($result === null || $result->outcome === FetchOutcome::Failed) {
                    $failed++;
                    $firstFailure ??= 'UID ' . $uid . ': ' . ($result?->error ?? '');
                    $checked++;
                    update_option(self::BACKLOG_OPTION, ['uid_validity' => $status->uidValidity, 'cursor' => $uid, 'mode' => 2], false);
                    $previous = $uid;
                    continue;
                }

                $proposal = $result->proposal;
                $this->table->forgetOpen($result->mail->messageId);

                if ($result->outcome === FetchOutcome::Proposed && $proposal !== null && $proposal->orderNumber !== ''
                    && ($model->findOrder($proposal->platform, $proposal->orderNumber) instanceof ExistingOrder
                        || (WonAuctionMatch::isWithoutNumber($proposal) && $model->findWonAuction($proposal) instanceof ExistingOrder))) {
                    $client->move($uid, $target);
                    $moved++;
                } elseif ($result->outcome === FetchOutcome::Proposed && $proposal !== null && $proposal->lines !== []
                    && ! in_array($proposal->kind, [ProposalKind::Sold, ProposalKind::Refunded, ProposalKind::Cancelled], true)) {
                    // Only what could become a new order: years of sale and refund mails would bury the page.
                    $this->table->forgetUnread($result->mail->messageId);

                    if ($this->table->add($result, $status->uidValidity) !== null) {
                        $stored++;
                    }
                }

                $checked++;
                update_option(self::BACKLOG_OPTION, ['uid_validity' => $status->uidValidity, 'cursor' => $uid, 'mode' => 2], false);
                $previous = $uid;
            }

            return ['checked' => $checked, 'moved' => $moved, 'stored' => $stored, 'remaining' => count($pending) - $checked,
                'failed' => $failed, 'first_failure' => $firstFailure];
        } finally {
            $client->logout();
            delete_transient(self::LOCK);
        }
    }

    /**
     * Reads the inbox mails that mention $text — typically an order number — whatever the starting point says, and
     * stores them like a fetch would. Nothing is moved: a mail no parser knows simply stays in the inbox.
     *
     * @return array{found: int, stored: int, proposed: int, unrecognised: int, failed: int}
     * @throws ImapException
     */
    public function fetchMatching(string $text): array
    {
        if (get_transient(self::LOCK)) {
            throw new ImapException('another fetch is running');
        }

        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);
        $client = $this->connect();

        try {
            $status = $client->select($this->settings->folders->inbox);
            $uids = array_slice($client->uidsContaining($text), 0, 20);
            $fetcher = new MailFetcher(Plugin::registry($this->settings));
            $counts = ['found' => count($uids), 'stored' => 0, 'proposed' => 0, 'unrecognised' => 0, 'failed' => 0];

            foreach ($uids as $uid) {
                $result = $fetcher->fetchOne($client, $uid);
                $this->table->forgetUnread($result->mail->messageId);

                if ($this->table->add($result, $status->uidValidity) !== null) {
                    $counts['stored']++;
                }

                match ($result->outcome) {
                    FetchOutcome::Proposed, FetchOutcome::NoReceipt => $counts['proposed']++,
                    FetchOutcome::Unrecognised                      => $counts['unrecognised']++,
                    default                                         => $counts['failed']++,
                };
            }

            return $counts;
        } finally {
            $client->logout();
            delete_transient(self::LOCK);
        }
    }

    /** Remembers the inbox as read up to now, without reading anything. */
    private function startAt(ImapClient $client): void
    {
        $status = $client->select($this->settings->folders->inbox);
        $uids = $status->uidNext > 0 ? [] : $client->uidsAfter(0);
        $lastUid = $status->uidNext > 0 ? $status->uidNext - 1 : ($uids === [] ? 0 : max($uids));

        update_option(self::STATE_OPTION, ['uid_validity' => $status->uidValidity, 'last_uid' => $lastUid], false);
    }

    /** @return array{fetched: int, stored: int, moved: int} */
    private function fetchLocked(): array
    {
        $client = $this->connect();

        try {
            $state = get_option(self::STATE_OPTION, []);
            $state = is_array($state) ? $state : [];

            // The very first fetch only sets the starting point: the mailbox holds years of mails that the Python
            // importer has handled already, and reading them from the oldest would flood the review page.
            if ($state === []) {
                $this->startAt($client);

                return ['fetched' => 0, 'stored' => 0, 'moved' => 0];
            }

            $status = $client->select($this->settings->folders->inbox);
            // New UIDVALIDITY: the old numbers mean nothing. Start over; the Message-ID keeps rows from doubling.
            $lastUid = (int) ($state['uid_validity'] ?? 0) === $status->uidValidity ? (int) ($state['last_uid'] ?? 0) : 0;

            $fetched = (new MailFetcher(Plugin::registry($this->settings)))->fetch($client, $lastUid, self::LIMIT);
            $stored = 0;
            $moved = 0;
            $canMove = $client->canMove();

            foreach ($fetched as $result) {
                if ($this->table->add($result, $status->uidValidity) !== null) {
                    $stored++;
                }

                $folderKey = ProposalTable::folderFor($result->outcome);

                if ($folderKey !== null && $canMove) {
                    $client->move($result->mail->uid, FolderPlan::onServer($this->folder($folderKey), $client->delimiter()));
                    $moved++;
                }

                $lastUid = max($lastUid, $result->mail->uid);
                update_option(self::STATE_OPTION, ['uid_validity' => $status->uidValidity, 'last_uid' => $lastUid], false);
            }

            return ['fetched' => count($fetched), 'stored' => $stored, 'moved' => $moved];
        } finally {
            $client->logout();
        }
    }

    private function folder(string $key): string
    {
        return match ($key) {
            'imported'     => $this->settings->folders->imported,
            'discarded'    => $this->settings->folders->discarded,
            default        => $this->settings->folders->unrecognised,
        };
    }

    /** @throws ImapException */
    public function connect(): ImapClient
    {
        $credentials = Settings::credentials() ?? throw new ImapException('no mailbox configured');
        $client = new ImapClient(new TlsSocketStream($credentials['host'], $credentials['port']));
        $client->login($credentials['user'], $credentials['password']);

        return $client;
    }
}
