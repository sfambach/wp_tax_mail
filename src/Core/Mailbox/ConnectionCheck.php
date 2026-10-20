<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use TaxMail\Core\Imap\ImapClient;

/**
 * The first build step of the concept (M3): can WordPress reach the mailbox, may it move mails, do the target
 * folders exist — creating those that are missing.
 */
final class ConnectionCheck
{
    public function __construct(private readonly FolderPlan $folders)
    {
    }

    /**
     * Expects a client that is already logged in.
     *
     * @return array{inbox_count: int, can_move: bool, delimiter: string, created: list<string>, present: list<string>}
     */
    public function run(ImapClient $client): array
    {
        $delimiter = $client->delimiter();
        $existing = $client->folders();
        $created = [];
        $present = [];
        $subscribe = [];

        foreach ($this->folders->targets() as $folder) {
            $onServer = FolderPlan::onServer($folder, $delimiter);
            $parents = explode($delimiter, $onServer);

            // Create «Taxmod» before «Taxmod.Importiert»; some servers do not create parents themselves.
            for ($depth = 1; $depth <= count($parents); $depth++) {
                $path = implode($delimiter, array_slice($parents, 0, $depth));

                if (in_array($path, $existing, true)) {
                    if ($depth === count($parents)) {
                        $present[] = $path;
                    }
                } else {
                    $client->createFolder($path);
                    $existing[] = $path;
                    $created[] = $path;
                }

                $subscribe[$path] = true;
            }
        }

        // Subscribing again is harmless, so every test also repairs folders created before 0.0.3.
        foreach (array_keys($subscribe) as $path) {
            $client->subscribe($path);
        }

        $inbox = $client->select($this->folders->inbox);

        return [
            'inbox_count' => $inbox->messageCount,
            'can_move'    => $client->canMove(),
            'delimiter'   => $delimiter,
            'created'     => $created,
            'present'     => $present,
        ];
    }
}
