<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Mailbox\SortRule;
use TaxMail\Core\Mailbox\SortRules;
use TaxMail\Core\Secret\SecretBox;
use TaxMail\Core\Secret\SecretException;

/**
 * The module's settings in one option: which parsers are on, the folder names, the schedule, the sorting rules. The mailbox account sits in
 * an option of its own with the password sealed (his word 2026-10-20: «ja verschlüsselt einbauen»); constants in
 * wp-config.php still win, for whoever prefers to keep it out of the database entirely.
 */
final class Settings
{
    public const OPTION = 'taxmail_settings';

    public const ACCOUNT_OPTION = 'taxmail_mailbox';

    /** WP-Cron recurrences he can pick; '' switches the automatic fetch off (his word 2026-10-20: «einstellbar»). */
    public const SCHEDULES = ['', 'hourly', 'twicedaily', 'daily'];

    /**
     * @param array<string, bool> $parsers
     */
    public function __construct(
        public readonly array $parsers,
        public readonly FolderPlan $folders,
        public readonly string $schedule = 'daily',
        public readonly SortRules $rules = new SortRules(),
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
            ),
            in_array($stored['schedule'] ?? 'daily', self::SCHEDULES, true) ? (string) ($stored['schedule'] ?? 'daily') : 'daily',
            new SortRules(array_values(array_map(
                static fn (array $rule): SortRule => new SortRule((string) ($rule[0] ?? ''), (string) ($rule[1] ?? ''), (string) ($rule[2] ?? '')),
                array_filter(is_array($stored['rules'] ?? null) ? $stored['rules'] : [], 'is_array')
            )))
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
            'schedule' => $this->schedule,
            'rules'    => array_map(static fn (SortRule $rule): array => [$rule->sender, $rule->subject, $rule->folder], $this->rules->rules),
        ], false);
    }

    /** True when the account comes from wp-config.php; the settings page then shows no fields for it. */
    public static function accountInConfig(): bool
    {
        return defined('TAXMAIL_IMAP_HOST') && defined('TAXMAIL_IMAP_USER') && defined('TAXMAIL_IMAP_PASSWORD');
    }

    /** @return array{host: string, port: int, user: string, password: string}|null null while no complete account is known */
    public static function credentials(): ?array
    {
        if (self::accountInConfig()) {
            return [
                'host'     => (string) constant('TAXMAIL_IMAP_HOST'),
                'port'     => defined('TAXMAIL_IMAP_PORT') ? (int) constant('TAXMAIL_IMAP_PORT') : 993,
                'user'     => (string) constant('TAXMAIL_IMAP_USER'),
                'password' => (string) constant('TAXMAIL_IMAP_PASSWORD'),
            ];
        }

        $account = self::storedAccount();

        if ($account['host'] === '' || $account['user'] === '' || $account['sealed'] === '') {
            return null;
        }

        try {
            $password = self::box()->open($account['sealed']);
        } catch (SecretException) {
            return null; // the salts changed since it was sealed; the settings page asks for the password again
        }

        return ['host' => $account['host'], 'port' => $account['port'], 'user' => $account['user'], 'password' => $password];
    }

    /** @return array{host: string, port: int, user: string, sealed: string, opens: bool} */
    public static function storedAccount(): array
    {
        $stored = get_option(self::ACCOUNT_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $sealed = (string) ($stored['password'] ?? '');
        $opens = false;

        if ($sealed !== '') {
            try {
                self::box()->open($sealed);
                $opens = true;
            } catch (SecretException) {
            }
        }

        return [
            'host'   => (string) ($stored['host'] ?? ''),
            'port'   => (int) ($stored['port'] ?? 993) ?: 993,
            'user'   => (string) ($stored['user'] ?? ''),
            'sealed' => $sealed,
            'opens'  => $opens,
        ];
    }

    /** An empty $password keeps the stored one, so the form never has to show it. */
    public static function saveAccount(string $host, int $port, string $user, string $password): void
    {
        $sealed = $password === '' ? self::storedAccount()['sealed'] : self::box()->seal($password);

        update_option(self::ACCOUNT_OPTION, ['host' => $host, 'port' => $port, 'user' => $user, 'password' => $sealed], false);
    }

    private static function box(): SecretBox
    {
        return new SecretBox(wp_salt('auth'));
    }
}
