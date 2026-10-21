<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

use TaxMail\Core\Imap\ImapClient;

/**
 * The first build step of the concept (M3): can WordPress reach the mailbox, may it move mails, do the target
 * folders exist — creating those that are missing.
 */
final class ConnectionCheck
{
    /** @param list<string> $extraFolders folders his sorting rules point at (D-949) */
    public function __construct(
        private readonly FolderPlan $folders,
        private readonly array $extraFolders = [],
    ) {
    }

    /**
     * Expects a client that is already logged in.
     *
     * @return array{inbox_count: int, can_move: bool, delimiter: string, created: list<string>, present: list<string>}
     */
    public function run(ImapClient $client): array
    {
        $delimiter = $client->delimiter();
        [$created, $present] = self::ensureFolders($client, array_values(array_unique([...$this->folders->targets(), ...$this->extraFolders])));

        $inbox = $client->select($this->folders->inbox);

        return [
            'inbox_count' => $inbox->messageCount,
            'can_move'    => $client->canMove(),
            'delimiter'   => $delimiter,
            'created'     => $created,
            'present'     => $present,
        ];
    }

    /**
     * Creates the folders that are missing and subscribes all of them; expects a client that is logged in.
     *
     * @param list<string> $folders written with «/»
     * @return array{0: list<string>, 1: list<string>} created, already present — as on the server
     */
    public static function ensureFolders(ImapClient $client, array $folders): array
    {
        $delimiter = $client->delimiter();
        $existing = $client->folders();
        $created = [];
        $present = [];
        $subscribe = [];

        foreach ($folders as $folder) {
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

        return [$created, $present];
    }
}
