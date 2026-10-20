<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Import\MailFetcher;
use TaxMail\Core\Mailbox\FolderPlan;

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
    private const LOCK = 'taxmail_fetch_lock';

    /** Mails per run — short runs fit Strato's time limits; the cron comes back in an hour. */
    private const LIMIT = 25;

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

    /** @return array{fetched: int, stored: int, moved: int} */
    private function fetchLocked(): array
    {
        $client = $this->connect();

        try {
            $status = $client->select($this->settings->folders->inbox);
            $state = get_option(self::STATE_OPTION, []);
            $state = is_array($state) ? $state : [];
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
        $credentials = Settings::credentials() ?? throw new ImapException('no mailbox configured in wp-config.php');
        $client = new ImapClient(new TlsSocketStream($credentials['host'], $credentials['port']));
        $client->login($credentials['user'], $credentials['password']);

        return $client;
    }
}
