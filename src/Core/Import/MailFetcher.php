<?php declare(strict_types=1);

namespace TaxMail\Core\Import;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Mail\MimeParser;
use TaxMail\Core\Parser\ParserException;
use TaxMail\Core\Parser\ParserRegistry;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * Reads the mails that arrived since the last run and lets the active parsers read them. It writes nothing and moves
 * nothing: the caller stores the results first, then moves — so a crash in between loses no mail.
 *
 * ```mermaid
 * flowchart LR
 *   U["UIDs after last seen"] --> F["fetch (peek)"]
 *   F --> M["MIME"]
 *   M --> P{"parser?"}
 *   P -->|none| X["Unrecognised"]
 *   P -->|reads it| O["Proposed / NoReceipt"]
 *   P -->|throws| E["Failed"]
 * ```
 */
final class MailFetcher
{
    public function __construct(
        private readonly ParserRegistry $parsers,
        private readonly MimeParser $mime = new MimeParser(),
    ) {
    }

    /**
     * @param int $lastSeenUid 0 for «from the start»
     * @param int $limit at most this many mails per run — Strato cuts long requests
     * @return list<FetchedMail>
     */
    public function fetch(ImapClient $client, int $lastSeenUid, int $limit): array
    {
        $results = [];

        foreach (array_slice($client->uidsAfter($lastSeenUid), 0, $limit) as $uid) {
            $results[] = $this->fetchOne($client, $uid);
        }

        return $results;
    }

    /** One mail by UID, for walks that choose their own UIDs (the backlog sort). */
    public function fetchOne(ImapClient $client, int $uid): FetchedMail
    {
        return $this->read($this->mime->parse($uid, $client->fetchRaw($uid)));
    }

    private function read(MailMessage $mail): FetchedMail
    {
        $parser = $this->parsers->parserFor($mail);

        if ($parser === null) {
            return new FetchedMail($mail, FetchOutcome::Unrecognised);
        }

        try {
            $proposal = $parser->parse($mail);
        } catch (ParserException $failure) {
            return new FetchedMail($mail, FetchOutcome::Failed, $parser->key(), null, $failure->getMessage());
        } catch (\Throwable $failure) {
            // A real mail the parser was not written for must cost that mail, not the whole fetch.
            return new FetchedMail($mail, FetchOutcome::Failed, $parser->key(), null,
                $failure::class . ': ' . $failure->getMessage() . ' (' . basename($failure->getFile()) . ':' . $failure->getLine() . ')');
        }

        $outcome = $proposal->kind === ProposalKind::NoReceipt ? FetchOutcome::NoReceipt : FetchOutcome::Proposed;
        // The shared format names its source and the source's own id (D-953); the parsers need not know.
        $proposal = OrderProposal::fromArray(['source' => 'mail', 'external_id' => $mail->messageId !== '' ? $mail->messageId : null] + $proposal->toArray());

        return new FetchedMail($mail, $outcome, $parser->key(), $proposal);
    }
}
