<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use Taxmod\Core\Import\Mail\MimeParser;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;

/**
 * Pickup notices that were discarded before D-1516 go back to the inbox: the server finds candidates by subject, and
 * only what PickupNotice accepts is moved — nothing else leaves the selected folder.
 */
final class PickupRescue
{
    public function __construct(private readonly MimeParser $mime = new MimeParser())
    {
    }

    /**
     * Works in the folder the client has selected; the newest $limit candidates are read.
     *
     * @return list<int> the UIDs moved
     * @throws ImapException
     */
    public function run(ImapClient $client, string $inbox, int $limit): array
    {
        $candidates = [];

        foreach (PickupNotice::searchTerms() as $term) {
            array_push($candidates, ...$client->uidsWithSubject($term));
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);
        $moved = [];

        foreach (array_slice($candidates, -$limit) as $uid) {
            if (PickupNotice::is($this->mime->parse($uid, $client->fetchRaw($uid)))) {
                $client->move($uid, $inbox);
                $moved[] = $uid;
            }
        }

        return $moved;
    }
}
