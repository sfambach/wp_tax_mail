<?php declare(strict_types=1);

namespace TaxMail\Core\Mailbox;

/**
 * Where a handled mail goes (concept §3a): the inbox keeps only what is still open.
 */
final class FolderPlan
{
    public function __construct(
        public readonly string $inbox = 'INBOX',
        public readonly string $imported = 'Taxmod/Importiert',
        public readonly string $discarded = 'Taxmod/Verworfen',
        public readonly string $unrecognised = 'Taxmod/Nicht erkannt',
    ) {
    }

    /** @return list<string> the target folders, written with «/» */
    public function targets(): array
    {
        return [$this->imported, $this->discarded, $this->unrecognised];
    }

    /** Settings write «/»; the server may want «.». */
    public static function onServer(string $folder, string $delimiter): string
    {
        return str_replace('/', $delimiter, $folder);
    }
}
