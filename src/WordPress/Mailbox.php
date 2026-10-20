<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Import\FetchOutcome;
use TaxMail\Core\Import\MailFetcher;
use TaxMail\Core\Mailbox\FolderPlan;
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
    private const LOCK = 'taxmail_fetch_lock';

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
     * Sorts the backlog — the mails before the starting point — a slice per call: a mail whose order is in the model
     * already goes to «Importiert», everything else stays in the inbox untouched and no proposal is made (§3a; his
     * choice 2026-10-20: «Neu ab jetzt + Altbestand per Knopf»).
     *
     * @return array{checked: int, moved: int, remaining: int}
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
            $cursor = is_array($backlog) && (int) ($backlog['uid_validity'] ?? 0) === $status->uidValidity ? (int) ($backlog['cursor'] ?? 0) : 0;
            $end = (int) $state['last_uid'];
            $pending = array_values(array_filter($client->uidsAfter($cursor), static fn (int $uid): bool => $uid <= $end));
            $fetcher = new MailFetcher(Plugin::registry($this->settings));
            $target = FolderPlan::onServer($this->folder('imported'), $client->delimiter());
            $started = microtime(true);
            $checked = 0;
            $moved = 0;

            foreach ($pending as $uid) {
                if (microtime(true) - $started > self::BACKLOG_SECONDS) {
                    break;
                }

                $result = $fetcher->fetchOne($client, $uid);
                $proposal = $result->proposal;

                if ($result->outcome === FetchOutcome::Proposed && $proposal !== null && $proposal->orderNumber !== ''
                    && $model->findOrder($proposal->platform, $proposal->orderNumber) instanceof ExistingOrder) {
                    $client->move($uid, $target);
                    $moved++;
                }

                $checked++;
                update_option(self::BACKLOG_OPTION, ['uid_validity' => $status->uidValidity, 'cursor' => $uid], false);
            }

            return ['checked' => $checked, 'moved' => $moved, 'remaining' => count($pending) - $checked];
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
