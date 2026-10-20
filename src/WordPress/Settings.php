<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Mailbox\FolderPlan;

/**
 * The module's settings in one option: which parsers are on, and the folder names. Credentials are not here but in
 * wp-config.php (D-942, M4), so no backup and no change log carries them.
 */
final class Settings
{
    public const OPTION = 'taxmail_settings';

    /**
     * @param array<string, bool> $parsers
     */
    public function __construct(
        public readonly array $parsers,
        public readonly FolderPlan $folders,
    ) {
    }

    public static function load(): self
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $folders = is_array($stored['folders'] ?? null) ? $stored['folders'] : [];
        $defaults = new FolderPlan();

        return new self(
            array_map('boolval', is_array($stored['parsers'] ?? null) ? $stored['parsers'] : []),
            new FolderPlan(
                (string) ($folders['inbox'] ?? $defaults->inbox),
                (string) ($folders['imported'] ?? $defaults->imported),
                (string) ($folders['discarded'] ?? $defaults->discarded),
                (string) ($folders['unrecognised'] ?? $defaults->unrecognised),
            )
        );
    }

    public function save(): void
    {
        update_option(self::OPTION, [
            'parsers' => $this->parsers,
            'folders' => [
                'inbox'        => $this->folders->inbox,
                'imported'     => $this->folders->imported,
                'discarded'    => $this->folders->discarded,
                'unrecognised' => $this->folders->unrecognised,
            ],
        ], false);
    }

    /** @return array{host: string, port: int, user: string, password: string}|null null while wp-config.php lacks them */
    public static function credentials(): ?array
    {
        if (! defined('TAXMAIL_IMAP_HOST') || ! defined('TAXMAIL_IMAP_USER') || ! defined('TAXMAIL_IMAP_PASSWORD')) {
            return null;
        }

        return [
            'host'     => (string) constant('TAXMAIL_IMAP_HOST'),
            'port'     => defined('TAXMAIL_IMAP_PORT') ? (int) constant('TAXMAIL_IMAP_PORT') : 993,
            'user'     => (string) constant('TAXMAIL_IMAP_USER'),
            'password' => (string) constant('TAXMAIL_IMAP_PASSWORD'),
        ];
    }
}
