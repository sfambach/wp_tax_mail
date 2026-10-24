<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Import\FetchOutcome;
use TaxMail\Core\Import\FetchedMail;
use TaxMail\Core\Import\MailFetcher;
use TaxMail\Core\Label\DhlLabelMail;
use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Mail\MimeParser;
use TaxMail\Core\Mailbox\ConnectionCheck;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Mailbox\SenderTally;
use TaxMail\Core\Mailbox\SortRules;
use Taxmod\Core\Import\BacklogSource;
use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\FetchBatch;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\InboxStatus;
use Taxmod\Core\Import\IncomingProposal;
use Taxmod\Core\Import\ModelLookup;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ResolvingSource;
use Taxmod\Core\Import\RestartingSource;
use Taxmod\Core\Import\SearchingSource;
use Taxmod\WordPress\Import\Duties;
use Taxmod\WordPress\Import\Sources;

/**
 * The purchasing mailbox as a source of the modeller's inbox (D-953): it reads mails, hands over what a parser read as
 * an order, and sorts the rest into folders. A proposal's mail stays in the inbox until the owner takes or discards
 * it; then the modeller says so and the mail moves.
 *
 * ```mermaid
 * flowchart LR
 *   F["fetch / backlog / search"] --> P{"order?"}
 *   P -->|yes| I["modeller's inbox; mail stays"]
 *   P -->|no| S["rules → folder"]
 *   I -->|taken / discarded| R["resolved → «Importiert» / «Verworfen»"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/konzept.md
 */
final class MailSource implements FetchingSource, BacklogSource, SearchingSource, RestartingSource, ResolvingSource
{
    public const KEY = 'mail';

    public const LOCK = 'taxmail_fetch_lock';

    /** The last thing that went wrong outside a page of this plugin — a mail that could not be moved after a take. */
    public const LAST_FAILURE = 'taxmail_last_failure';

    /** Where tax_mail 0.0.x kept its starting point; read once when the modeller has no cursor yet, gone after a restart. */
    private const LEGACY_STATE = 'taxmail_state';

    /** A backlog mail that ended the last slice without a word (memory, time limit) — skipped next time. */
    private const TRYING = 'taxmail_backlog_trying';

    /** Mails per fetch — keeps a run inside Strato's time limits; the rest comes with the next run. */
    private const LIMIT = 60;

    /** Seconds per backlog slice; the modeller calls slices until its own budget is spent. */
    private const SLICE_SECONDS = 5;

    /** Mails «Bestellung holen» reads at most. */
    private const SEARCH_LIMIT = 20;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Mail', 'taxmail');
    }

    /** Only the default: his choice is the modeller's «Fetch interval» (D-981); 0 without a mailbox keeps the fetch off. */
    public function interval(): int
    {
        return Settings::credentials() === null ? 0 : DAY_IN_SECONDS;
    }

    /**
     * New mails since the cursor. The very first run only sets the starting point: the mailbox holds years of mails the
     * Python importer handled already; the backlog reads those on request.
     *
     * @throws ImapException
     */
    public function fetch(?string $cursor): FetchBatch
    {
        return $this->locked(function (ImapClient $client) use ($cursor): FetchBatch {
            $status = $client->select($this->settings->folders->inbox);
            [$validity, $lastUid] = self::position($cursor ?? self::legacyCursor());

            if ($validity === null) {
                return new FetchBatch([], self::cursor($status->uidValidity, self::lastUid($client, $status->uidNext)));
            }

            // New UIDVALIDITY: the old numbers mean nothing. Start over; the Message-ID keeps proposals from doubling.
            $lastUid = $validity === $status->uidValidity ? $lastUid : 0;
            $proposals = [];
            $canMove = $client->canMove();
            $this->ensureRuleFolders($client);

            foreach ((new MailFetcher(Plugin::registry($this->settings)))->fetch($client, $lastUid, self::LIMIT) as $result) {
                $target = $this->tookLabel($result) ? $this->settings->folders->imported : $this->sortTarget($result, $this->outcomeFolder($result->outcome));

                if ($target === null && $result->outcome === FetchOutcome::Proposed && $result->proposal !== null) {
                    $proposals[] = $this->incoming($result, $status->uidValidity);
                } elseif ($target !== null && $canMove) {
                    $client->move($result->mail->uid, FolderPlan::onServer($target, $client->delimiter()));
                }

                $lastUid = max($lastUid, $result->mail->uid);
            }

            return new FetchBatch($proposals, self::cursor($status->uidValidity, $lastUid));
        });
    }

    /**
     * One slice of the inbox from the oldest mail on: a mail whose order is in the model already goes to «Importiert», a
     * mail that could bring a new order becomes a proposal, the rules sort the rest, and what no rule names stays (his
     * word 2026-10-20: «alle mail nochmal parsen lassen und doppelte aussortieren»).
     *
     * @throws ImapException
     */
    public function backlog(?string $cursor, ModelLookup $model): FetchBatch
    {
        return $this->locked(function (ImapClient $client) use ($cursor, $model): FetchBatch {
            $status = $client->select($this->settings->folders->inbox);
            [$validity, $from] = self::position($cursor);
            $from = $validity === $status->uidValidity ? $from : 0;
            $crashed = (int) get_option(self::TRYING, 0);

            if ($crashed > $from) {
                $from = $crashed;
                update_option(self::LAST_FAILURE, current_time('mysql') . ' — UID ' . $crashed . ': ' . __('the last backlog slice stopped on this mail; it stays in the inbox', 'taxmail'), false);
            }

            $pending = $client->uidsAfter($from);
            $proposals = [];
            $this->ensureRuleFolders($client);

            try {
                $last = $this->backlogSlice($client, $pending, $from, $status->uidValidity, $model, $proposals);
            } finally {
                // Only a fatal error (memory, time limit) leaves the mark behind — that mail is skipped next time.
                delete_option(self::TRYING);
            }

            $done = $pending === [] || $last >= max($pending);

            return new FetchBatch($proposals, $done ? null : self::cursor($status->uidValidity, $last));
        });
    }

    /**
     * @param list<int> $pending
     * @param list<IncomingProposal> $proposals filled with what could become a new order
     * @return int the last UID handled
     */
    private function backlogSlice(ImapClient $client, array $pending, int $from, int $uidValidity, ModelLookup $model, array &$proposals): int
    {
        $fetcher = new MailFetcher(Plugin::registry($this->settings));
        $imported = FolderPlan::onServer($this->settings->folders->imported, $client->delimiter());
        $sources = array_keys(Sources::all());
        $duties = Duties::load();
        $started = microtime(true);
        $last = $from;

        foreach ($pending as $uid) {
            if (microtime(true) - $started > self::SLICE_SECONDS) {
                break;
            }

            update_option(self::TRYING, $uid, false);

            try {
                $result = $fetcher->fetchOne($client, $uid);
            } catch (ImapException $failure) {
                throw $failure;
            } catch (\Throwable) {
                // One mail nobody planned for must not stop the backlog at the same place on every click.
                $result = null;
            }

            $last = $uid;
            $proposal = $result?->proposal;

            if ($result === null || $result->outcome === FetchOutcome::Failed) {
                continue;
            }

            if ($this->tookLabel($result)) {
                $client->move($uid, $imported);
            } elseif ($result->outcome === FetchOutcome::Proposed && $proposal !== null && $proposal->orderNumber !== ''
                && ($proposal->numberMissing ? $model->findLoose($proposal) : $model->findOrder($proposal->platform, $proposal->orderNumber)) !== null) {
                $client->move($uid, $imported);
            } elseif ($result->outcome === FetchOutcome::Proposed && $proposal !== null && $proposal->lines !== []
                && ! in_array($proposal->kind, [ProposalKind::Sold, ProposalKind::Refunded, ProposalKind::Cancelled], true)
                && $duties->allows(self::KEY, $proposal->platform, DutyRole::Orders, $sources)) {
                // Only what could become a new order: years of sale and refund mails would bury the page.
                $proposals[] = $this->incoming($result, $uidValidity);
            } elseif (($sorted = $this->sortTarget($result, null)) !== null) {
                $client->move($uid, FolderPlan::onServer($sorted, $client->delimiter()));
            }
        }

        return $last;
    }

    /**
     * The inbox mails that mention $text — typically an order number — whatever the starting point says. Nothing is
     * moved: a mail no parser knows simply stays.
     *
     * @throws ImapException
     */
    public function search(string $text): FetchBatch
    {
        // Order numbers are letters, digits and dashes; anything else is dropped so the IMAP search string stays plain.
        $text = (string) preg_replace('/[^A-Za-z0-9-]/', '', $text);

        if (strlen($text) < 4) {
            throw new ImapException(__('enter an order number of at least 4 characters', 'taxmail'));
        }

        return $this->locked(function (ImapClient $client) use ($text): FetchBatch {
            $status = $client->select($this->settings->folders->inbox);
            $fetcher = new MailFetcher(Plugin::registry($this->settings));
            $proposals = [];

            foreach (array_slice($client->uidsContaining($text), 0, self::SEARCH_LIMIT) as $uid) {
                $result = $fetcher->fetchOne($client, $uid);

                if ($result->outcome === FetchOutcome::Proposed && $result->proposal !== null) {
                    $proposals[] = $this->incoming($result, $status->uidValidity);
                }
            }

            return new FetchBatch($proposals, null);
        });
    }

    /**
     * The mail side of starting over: mails sorted away on their own («Nicht erkannt», «Verworfen») go back to the
     * inbox; the next fetch then starts from that moment. «Importiert» stays as it is.
     *
     * @throws ImapException
     */
    public function restart(): void
    {
        $this->locked(function (ImapClient $client): void {
            $folders = $client->folders();

            foreach ([$this->settings->folders->unrecognised, $this->settings->folders->discarded] as $name) {
                $folder = FolderPlan::onServer($name, $client->delimiter());

                if (! in_array($folder, $folders, true)) {
                    continue;
                }

                $client->select($folder);

                foreach ($client->uidsAfter(0) as $uid) {
                    $client->move($uid, $this->settings->folders->inbox);
                }
            }

            delete_option(self::LEGACY_STATE);
            delete_option(self::TRYING);
        });
    }

    /**
     * Moves the mail of a settled proposal out of the inbox. A failure must not undo the take that already happened;
     * it is kept for the settings page instead.
     *
     * @param array<string, scalar|null> $evidence
     */
    public function resolved(array $evidence, InboxStatus $status): void
    {
        $folder = match ($status) {
            InboxStatus::Imported  => $this->settings->folders->imported,
            InboxStatus::Discarded => $this->settings->folders->discarded,
            InboxStatus::Open      => null,
        };

        if ($folder === null) {
            return;
        }

        try {
            $client = $this->connect();

            try {
                $current = $client->select($this->settings->folders->inbox);
                $messageId = (string) ($evidence['message_id'] ?? '');
                $uid = (int) ($evidence['uid_validity'] ?? 0) === $current->uidValidity
                    ? (int) ($evidence['uid'] ?? 0)
                    : ($messageId !== '' ? $client->uidByMessageId($messageId) : null);

                if ($uid !== null && $uid > 0 && $client->canMove()) {
                    $client->move($uid, FolderPlan::onServer($folder, $client->delimiter()));
                }
            } finally {
                $client->logout();
            }
        } catch (ImapException $failure) {
            /* translators: %s: the server's answer */
            update_option(self::LAST_FAILURE, current_time('mysql') . ' — ' . sprintf(__('a mail could not be moved: %s', 'taxmail'), $failure->getMessage()), false);
        }
    }

    /**
     * The most frequent senders in the inbox, for writing sorting rules (D-949).
     *
     * @return array<string, int> sender => mails
     * @throws ImapException
     */
    public function topSenders(): array
    {
        $client = $this->connect();

        try {
            $status = $client->select($this->settings->folders->inbox);

            return $status->messageCount === 0 ? [] : SenderTally::count($client->fromHeaders());
        } finally {
            $client->logout();
        }
    }

    /**
     * The newest mails of one sender from the inbox and «Nicht erkannt», for writing a parser for a shop that has none
     * yet. Nothing is moved and nothing is marked read.
     *
     * @return list<MailMessage>
     * @throws ImapException
     */
    public function samples(string $sender, int $limit): array
    {
        $client = $this->connect();
        $mails = [];

        try {
            $folders = $client->folders();

            foreach ([$this->settings->folders->inbox, $this->settings->folders->unrecognised] as $name) {
                $folder = FolderPlan::onServer($name, $client->delimiter());

                if ($name !== $this->settings->folders->inbox && ! in_array($folder, $folders, true)) {
                    continue;
                }

                $client->select($folder);

                foreach (array_slice($client->uidsFrom($sender), -$limit) as $uid) {
                    $mails[] = (new MimeParser())->parse($uid, $client->fetchRaw($uid));
                }
            }
        } finally {
            $client->logout();
        }

        usort($mails, static fn (MailMessage $a, MailMessage $b): int => $b->date <=> $a->date);

        return array_slice($mails, 0, $limit);
    }

    /** @throws ImapException */
    public function connect(): ImapClient
    {
        $credentials = Settings::credentials() ?? throw new ImapException(__('no mailbox configured', 'taxmail'));
        $client = new ImapClient(new TlsSocketStream($credentials['host'], $credentials['port']));
        $client->login($credentials['user'], $credentials['password']);

        return $client;
    }

    /** What the inbox keeps beside the proposal: enough to find the mail again and to say where it came from — never a secret (D-951). */
    private function incoming(FetchedMail $result, int $uidValidity): IncomingProposal
    {
        $mail = $result->mail;
        $received = $mail->date?->setTimezone(wp_timezone());

        return new IncomingProposal($result->proposal, [
            'uid'          => $mail->uid,
            'uid_validity' => $uidValidity,
            'message_id'   => $mail->messageId,
            'sender'       => $mail->fromAddress,
            'subject'      => $mail->subject,
            'received_at'  => $received?->format('Y-m-d H:i:s'),
            'parser'       => $result->parserKey,
            /* translators: 1: platform, 2: mail subject, 3: date of the mail */
            'origin'       => sprintf(__('From %1$s mail «%2$s» of %3$s.', 'taxmail'), $result->proposal->platform, $mail->subject, $received?->format('Y-m-d') ?? '?'),
        ]);
    }

    /** A DHL label mail is kept for the eBay module and counts as handled (moved to «Importiert»). */
    private function tookLabel(FetchedMail $result): bool
    {
        $label = (new DhlLabelMail())->read($result->mail);

        if ($label === null) {
            return false;
        }

        ShippingLabels::remember($label);

        return true;
    }

    /**
     * Where a mail goes that does not become an order proposal: his rules first (D-949), then the fixed rule that a
     * shop's mail without an order — advertising, his own sales — is discarded. Null leaves it in the inbox.
     */
    private function sortTarget(FetchedMail $result, ?string $default): ?string
    {
        $proposal = $result->proposal;
        $isOrder = $result->outcome === FetchOutcome::Proposed && $proposal !== null
            && $proposal->kind !== ProposalKind::Sold && ($proposal->lines !== [] || $proposal->orderNumber !== '');

        if ($isOrder) {
            return null;
        }

        $ruled = $this->settings->rules->folderFor($result->mail->fromAddress, $result->mail->subject);

        if ($ruled !== null) {
            return SortRules::resolve($ruled, $this->settings->folders);
        }

        if ($result->outcome === FetchOutcome::NoReceipt || ($proposal !== null && $proposal->kind === ProposalKind::Sold)) {
            return $this->settings->folders->discarded;
        }

        return $default;
    }

    private function outcomeFolder(FetchOutcome $outcome): ?string
    {
        return match ($outcome) {
            FetchOutcome::Proposed     => null,
            FetchOutcome::NoReceipt    => $this->settings->folders->discarded,
            FetchOutcome::Unrecognised,
            FetchOutcome::Failed       => $this->settings->folders->unrecognised,
        };
    }

    /** A rule may name a folder of his own; it is created the first time it is needed. */
    private function ensureRuleFolders(ImapClient $client): void
    {
        $folders = $this->settings->rules->folders($this->settings->folders);

        if ($folders !== []) {
            ConnectionCheck::ensureFolders($client, $folders);
        }
    }

    /**
     * One IMAP session at a time: two runs on the same mailbox would move each other's mails.
     *
     * @template T
     * @param callable(ImapClient): T $work
     * @return T
     * @throws ImapException
     */
    private function locked(callable $work): mixed
    {
        if (get_transient(self::LOCK)) {
            throw new ImapException(__('another mail run is busy', 'taxmail'));
        }

        set_transient(self::LOCK, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $client = $this->connect();

            try {
                return $work($client);
            } finally {
                $client->logout();
            }
        } finally {
            delete_transient(self::LOCK);
        }
    }

    private static function lastUid(ImapClient $client, int $uidNext): int
    {
        if ($uidNext > 0) {
            return $uidNext - 1;
        }

        $uids = $client->uidsAfter(0);

        return $uids === [] ? 0 : max($uids);
    }

    private static function legacyCursor(): ?string
    {
        $state = get_option(self::LEGACY_STATE, []);

        return is_array($state) && isset($state['uid_validity'], $state['last_uid'])
            ? self::cursor((int) $state['uid_validity'], (int) $state['last_uid'])
            : null;
    }

    private static function cursor(int $uidValidity, int $uid): string
    {
        return $uidValidity . ':' . $uid;
    }

    /** @return array{0: ?int, 1: int} UIDVALIDITY (null without a cursor) and the last UID read */
    private static function position(?string $cursor): array
    {
        if ($cursor === null || preg_match('/^(\d+):(\d+)$/', $cursor, $match) !== 1) {
            return [null, 0];
        }

        return [(int) $match[1], (int) $match[2]];
    }
}
