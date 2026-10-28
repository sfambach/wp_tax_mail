<?php declare(strict_types=1);

namespace TaxMail\Core\Import;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Mail\MailMessage;
use TaxMail\Core\Mail\MimeParser;
use TaxMail\Core\Parser\MultiOrderMailParser;
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
            $proposals = $parser instanceof MultiOrderMailParser ? $parser->parseAll($mail) : [$parser->parse($mail)];
        } catch (ParserException $failure) {
            return new FetchedMail($mail, FetchOutcome::Failed, $parser->key(), null, $failure->getMessage());
        } catch (\Throwable $failure) {
            // A real mail the parser was not written for must cost that mail, not the whole fetch.
            return new FetchedMail($mail, FetchOutcome::Failed, $parser->key(), null,
                $failure::class . ': ' . $failure->getMessage() . ' (' . basename($failure->getFile()) . ':' . $failure->getLine() . ')');
        }

        $outcome = $proposals[0]->kind === ProposalKind::NoReceipt ? FetchOutcome::NoReceipt : FetchOutcome::Proposed;
        $proposals = array_map(static function (OrderProposal $proposal) use ($mail): OrderProposal {
            // The shared format names its source and the source's own id (D-953); the parsers need not know.
            $row = ['source' => 'mail', 'external_id' => $mail->messageId !== '' ? $mail->messageId : null] + $proposal->toArray();

            // A shipping or delivery mail is dated when the parcel moved, not when he ordered. Parsers gave that date as
            // the order date, so every status mail of an order in the model proposed a wrong «ordered on» — for eBay as
            // «another module is responsible». Decided here once for every parser.
            if (in_array($proposal->kind, [ProposalKind::Shipped, ProposalKind::Delivered], true)) {
                $row['order_date'] = null;
            }

            // Shops that send through a tracking service (Conrad via parcelLab) mark the parcel with schema.org's
            // «trackingNumber» while the text only links it. Read for every parser that found none of its own.
            if ($row['tracking'] === [] && in_array($proposal->kind, [ProposalKind::Shipped, ProposalKind::Delivered], true)
                && preg_match_all('/itemprop="trackingNumber"\s+content="([A-Za-z0-9]{8,40})"/', $mail->html, $found) > 0) {
                $row['tracking'] = array_values(array_unique($found[1]));
            }

            return OrderProposal::fromArray($row);
        }, $proposals);

        return new FetchedMail($mail, $outcome, $parser->key(), $proposals[0], null, $proposals);
    }
}
