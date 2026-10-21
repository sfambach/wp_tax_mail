<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Mailbox\ConnectionCheck;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Mailbox\SortRules;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\ParserRegistry;
use TaxMail\Core\Parser\ShippedParsers;
use Taxmod\Core\Import\BacklogSource;
use Taxmod\WordPress\Admin\SettingsScreen as ConfigurationScreen;
use Taxmod\WordPress\Import\InboxScreen;
use Taxmod\WordPress\Import\Sources;

/**
 * The boundary: registers the mailbox as a source of the modeller's inbox (D-953) and its settings as the tab «Mail» of
 * the modeller's Configuration page (D-945) — account, parsers, folders, sorting rules, the connection test. It wires WordPress to the core and decides nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md
 */
final class Plugin
{
    public const VERSION     = '0.1.1';
    public const TEXT_DOMAIN = 'taxmail';
    public const CAPABILITY  = 'manage_options';
    public const TAB         = 'mail';

    /** Where the last connection test's outcome waits for the redirect — per user, ten minutes. */
    private const CHECK_RESULT = 'taxmail_check_';

    /** The last sender count per user, shown beside the sorting rules. */
    public const SENDERS = 'taxmail_senders_';

    public static function boot(string $file): void
    {
        $plugin = new self();

        // The inbox, the proposal format and the vault live in the modeller since D-952/D-953; whether it is loaded is
        // known only once every plugin is.
        add_action('plugins_loaded', static function () use ($plugin, $file): void {
            if (! self::modellerReady()) {
                add_action('admin_notices', static fn () => print '<div class="notice notice-error"><p><strong>Taxmod Mail:</strong> '
                    . esc_html__('needs the Taxonomy Modeller 0.1.36 or later — the mail import is off until it is active.', 'taxmail') . '</p></div>');

                return;
            }

            $plugin->hook($file);
        });
    }

    public static function modellerReady(): bool
    {
        return interface_exists(BacklogSource::class) && class_exists(Sources::class);
    }

    private function hook(string $file): void
    {
        $plugin = $this;

        add_action('init', static fn () => load_plugin_textdomain('taxmail', false, dirname(plugin_basename($file)) . '/languages'));
        add_action('admin_init', LegacyProposals::moveOnce(...));
        add_filter(Sources::FILTER, static fn (array $sources): array => [...$sources, new MailSource(Settings::load())]);
        // After the modeller's own «Import» tab.
        add_filter(ConfigurationScreen::TABS_FILTER, $plugin->registerTab(...), 20);
        add_action('admin_post_taxmail_check', $plugin->handleCheck(...));
        add_action('admin_post_taxmail_senders', $plugin->handleSenders(...));
        add_filter('wp_php_error_message', self::explainFatal(...), 10, 2);
    }

    /**
     * Every parser another plugin or this one announces. A mail connection is «a kind of plugin» (D-942): adding one
     * means hooking this filter, nothing in here changes.
     */
    public static function registry(Settings $settings): ParserRegistry
    {
        $parsers = array_values(array_filter(
            (array) apply_filters('taxmail_parsers', ShippedParsers::all()),
            static fn (mixed $parser): bool => $parser instanceof OrderMailParser
        ));

        return new ParserRegistry($parsers, $settings->parsers);
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function registerTab(array $tabs): array
    {
        $tabs[self::TAB] = [
            'title'  => __('Mail', 'taxmail'),
            'render' => static function (): string {
                $user = get_current_user_id();
                $result = get_transient(self::CHECK_RESULT . $user);
                delete_transient(self::CHECK_RESULT . $user);

                return (new SettingsScreen())->render(Settings::load(), is_array($result) ? $result : null, admin_url('admin.php?page=' . InboxScreen::PAGE));
            },
            'save'   => $this->save(...),
        ];

        return $tabs;
    }

    /**
     * The Configuration page checked capability and nonce and unslashed the fields (D-945); this validates and
     * sanitises them (CD-5).
     *
     * @param array<string, mixed> $post `mixed` because it is the posted form
     */
    public function save(array $post): void
    {
        $parsers = [];
        $posted = isset($post['parsers']) && is_array($post['parsers']) ? $post['parsers'] : [];
        $settings = Settings::load();

        foreach (array_keys(self::registry($settings)->all()) as $key) {
            $parsers[$key] = isset($posted[$key]);
        }

        $folders = isset($post['folders']) && is_array($post['folders']) ? $post['folders'] : [];
        $folder = static fn (string $key, string $fallback): string => self::folderName($folders[$key] ?? '', $fallback);
        $defaults = $settings->folders;

        $schedule = isset($post['schedule']) ? sanitize_key((string) $post['schedule']) : 'daily';

        if (! Settings::accountInConfig() && isset($post['account']) && is_array($post['account'])) {
            $account = $post['account'];
            $port = absint($account['port'] ?? 993);
            Settings::saveAccount(
                sanitize_text_field((string) ($account['host'] ?? '')),
                $port >= 1 && $port <= 65535 ? $port : 993,
                sanitize_text_field((string) ($account['user'] ?? '')),
                (string) ($account['password'] ?? '') // a password is taken as typed: sanitizing would change it
            );
        }

        (new Settings($parsers, new FolderPlan(
            $folder('inbox', $defaults->inbox),
            $folder('imported', $defaults->imported),
            $folder('discarded', $defaults->discarded),
            $folder('unrecognised', $defaults->unrecognised),
        ), in_array($schedule, Settings::SCHEDULES, true) ? $schedule : 'daily',
            // Each line is split on «|» by SortRules; sanitize_textarea_field keeps the line breaks.
            SortRules::fromText(isset($post['rules']) ? sanitize_textarea_field((string) $post['rules']) : '')))->save();
    }

    /** Counts the senders in the inbox for the sorting rules (D-949); nothing is moved. */
    public function handleSenders(): void
    {
        $this->guard('taxmail_senders');

        try {
            $senders = (new MailSource(Settings::load()))->topSenders();
            set_transient(self::SENDERS . get_current_user_id(), $senders, HOUR_IN_SECONDS);
        } catch (ImapException $failure) {
            set_transient(self::CHECK_RESULT . get_current_user_id(), ['ok' => false, 'error' => 'imap', 'detail' => $failure->getMessage()], 10 * MINUTE_IN_SECONDS);
        }

        $this->back();
    }

    public function handleCheck(): void
    {
        $this->guard('taxmail_check');

        $credentials = Settings::credentials();

        if ($credentials === null) {
            $result = ['ok' => false, 'error' => 'no_credentials'];
        } else {
            try {
                $client = new ImapClient(new TlsSocketStream($credentials['host'], $credentials['port']));
                $client->login($credentials['user'], $credentials['password']);
                $settings = Settings::load();
                $result = ['ok' => true] + (new ConnectionCheck($settings->folders, $settings->rules->folders($settings->folders)))->run($client);
                $client->logout();
            } catch (ImapException $failure) {
                $result = ['ok' => false, 'error' => 'imap', 'detail' => $failure->getMessage()];
            }
        }

        set_transient(self::CHECK_RESULT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        $this->back();
    }

    /** CD-5, in this order: capability, then nonce. */
    private function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You may not change the mail import.', 'taxmail'), 403);
        }

        check_admin_referer($action);
    }

    /** Back to the «Mail» tab of the Configuration page. */
    private function back(): never
    {
        wp_safe_redirect(admin_url('admin.php?page=taxmod-settings&tab=' . self::TAB));
        exit;
    }

    /**
     * WordPress's «critical error» page names nothing, and the server log is not his to read: for an administrator, a
     * fatal error on one of this plugin's screens is spelled out on that page.
     *
     * @param array{type?: int, message?: string, file?: string, line?: int} $error
     */
    public static function explainFatal(string $message, array $error): string
    {
        // Read-only look at which screen died; nothing is acted on.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        if ($tab !== self::TAB && ! str_starts_with($action, 'taxmail_')
            || ! function_exists('current_user_can') || ! current_user_can('manage_options')) {
            return $message;
        }

        return $message . '<p><code>' . esc_html(sprintf('%s (%s:%d)', strtok((string) ($error['message'] ?? ''), "\n"),
            basename((string) ($error['file'] ?? '')), (int) ($error['line'] ?? 0))) . '</code></p>';
    }

    private static function folderName(mixed $value, string $fallback): string
    {
        $name = trim(sanitize_text_field(is_string($value) ? $value : ''));

        return $name === '' ? $fallback : $name;
    }
}
