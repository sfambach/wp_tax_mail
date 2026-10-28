<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Export\AmazonOrderHistory;
use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Mailbox\ConnectionCheck;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Mailbox\SortRules;
use Taxmod\Core\Import\Mail\OrderMailParser;
use TaxMail\Core\Parser\ParserRegistry;
use Taxmod\Core\Import\BacklogSource;
use Taxmod\WordPress\Admin\SettingsScreen as ConfigurationScreen;
use Taxmod\WordPress\Import\FetchInterval;
use Taxmod\WordPress\Import\InboxScreen;
use Taxmod\WordPress\Import\Sources;

/**
 * The boundary: registers the mailbox as a source of the modeller's inbox (D-953) and its settings as the tab «Mail» of
 * the modeller's Configuration page (D-945) in the modeller's order: access, fetching, settings (D-981). It wires
 * WordPress to the core and decides nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md
 */
final class Plugin
{
    public const VERSION     = '0.6.0';
    public const TEXT_DOMAIN = 'taxmail';
    public const CAPABILITY  = 'manage_options';
    public const TAB         = 'mail';

    /** Why the last sender count failed, for the redirect — per user, ten minutes. */
    private const SENDERS_FAILURE = 'taxmail_senders_failure_';

    /** What the last export upload brought, for the redirect. */
    public const EXPORT_DONE = 'taxmail_export_done_';

    /** The id of the upload form, printed outside the Configuration page's own form. */
    public const EXPORT_FORM = 'taxmail-export';

    /** What the last «Save samples» wrote, for the redirect. */
    public const SAMPLES_DONE = 'taxmail_samples_done_';

    /** Set once the 0.1.x «schedule» setting has become the modeller's fetch interval (D-981). */
    private const INTERVAL_MOVED = 'taxmail_interval_moved';

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
                    . esc_html__('needs the Taxonomy Modeller 0.1.81 or later — the mail import is off until it is active.', 'taxmail') . '</p></div>');

                return;
            }

            $plugin->hook($file);
        });
    }

    public static function modellerReady(): bool
    {
        return interface_exists(BacklogSource::class) && class_exists(Sources::class) && class_exists(FetchInterval::class) && interface_exists(OrderMailParser::class);
    }

    private function hook(string $file): void
    {
        $plugin = $this;

        add_action('init', static fn () => load_plugin_textdomain('taxmail', false, dirname(plugin_basename($file)) . '/languages'));
        add_action('admin_init', LegacyProposals::moveOnce(...));
        add_action('admin_init', self::moveIntervalOnce(...));
        add_filter(Sources::FILTER, static fn (array $sources): array => [...$sources, new MailSource(Settings::load())]);
        // After the modeller's own «Import» tab.
        add_filter(ConfigurationScreen::TABS_FILTER, $plugin->registerTab(...), 20);
        add_action('admin_post_taxmail_senders', $plugin->handleSenders(...));
        add_action('admin_post_taxmail_samples', $plugin->handleSamples(...));
        add_action('admin_post_taxmail_samples_delete', $plugin->handleSamplesDelete(...));
        add_action('admin_post_taxmail_export', $plugin->handleExport(...));
        add_action('admin_post_taxmail_export_delete', $plugin->handleExportDelete(...));
        add_action('admin_footer', self::exportForm(...));
        add_filter(ShippingLabels::FILTER, ShippingLabels::filter(...));
        add_filter('wp_php_error_message', self::explainFatal(...), 10, 2);
    }

    /**
     * Every parser another plugin or this one announces. A mail connection is «a kind of plugin» (D-942): adding one
     * means hooking this filter, nothing in here changes.
     */
    /** The readers the shop modules register (D-1006); this module brings none of its own. */
    public static function registry(): ParserRegistry
    {
        $parsers = [];

        foreach ((array) apply_filters('taxmail_parsers', []) as $parser) {
            if ($parser instanceof OrderMailParser) {
                $parsers[$parser->key()] ??= $parser;
            }
        }

        return new ParserRegistry(array_values($parsers));
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function registerTab(array $tabs): array
    {
        $inboxUrl = admin_url('admin.php?page=' . InboxScreen::PAGE);
        $tabs[self::TAB] = [
            'title'       => __('Mail', 'taxmail'),
            'version'     => self::VERSION,
            'source'      => MailSource::KEY,
            'credentials' => static fn (): string => SettingsScreen::account(),
            'test'        => self::test(...),
            'forget'      => Settings::accountInConfig() ? null : Settings::forgetAccount(...),
            'render'      => static function () use ($inboxUrl): string {
                $failure = get_transient(self::SENDERS_FAILURE . get_current_user_id());
                delete_transient(self::SENDERS_FAILURE . get_current_user_id());

                return (new SettingsScreen())->render(Settings::load(), is_string($failure) ? $failure : '', $inboxUrl);
            },
            'save'        => $this->save(...),
            'guide'       => static fn (): string => SettingsScreen::guide($inboxUrl),
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
        $settings = Settings::load();

        $folders = isset($post['folders']) && is_array($post['folders']) ? $post['folders'] : [];
        $folder = static fn (string $key, string $fallback): string => self::folderName($folders[$key] ?? '', $fallback);
        $defaults = $settings->folders;

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

        (new Settings(new FolderPlan(
            $folder('inbox', $defaults->inbox),
            $folder('imported', $defaults->imported),
            $folder('discarded', $defaults->discarded),
            $folder('unrecognised', $defaults->unrecognised),
        ),
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
            set_transient(self::SENDERS_FAILURE . get_current_user_id(), $failure->getMessage(), 10 * MINUTE_IN_SECONDS);
        }

        $this->back();
    }

    /** Writes the newest mails of one sender as sample files, for a parser still to be written. */
    public function handleSamples(): void
    {
        $this->guard('taxmail_samples');

        $sender = isset($_GET['sender']) ? sanitize_text_field(wp_unslash((string) $_GET['sender'])) : '';

        if (preg_match('/^[A-Za-z0-9@._+-]{3,}$/', $sender) !== 1) {
            wp_die(esc_html__('That is no sender address.', 'taxmail'), 400);
        }

        try {
            $written = SampleMails::write($sender, (new MailSource(Settings::load()))->samples($sender, SampleMails::LIMIT));
            /* translators: 1: number of mails, 2: sender */
            set_transient(self::SAMPLES_DONE . get_current_user_id(), sprintf(__('%1$d sample mails of %2$s saved.', 'taxmail'), $written, $sender), 10 * MINUTE_IN_SECONDS);
        } catch (ImapException $failure) {
            set_transient(self::SENDERS_FAILURE . get_current_user_id(), $failure->getMessage(), 10 * MINUTE_IN_SECONDS);
        }

        $this->back();
    }

    public function handleSamplesDelete(): void
    {
        $this->guard('taxmail_samples_delete');
        SampleMails::deleteAll();
        $this->back();
    }

    /**
     * Reads an uploaded «Order History.csv» into the export queue. Only the proposals are kept; the upload itself is the
     * temporary file PHP removes after the request.
     */
    public function handleExport(): void
    {
        $this->guard('taxmail_export');

        $file = $_FILES['export'] ?? null;
        $scope = isset($_POST['scope']) && sanitize_key(wp_unslash((string) $_POST['scope'])) === 'all' ? 'all' : 'technical';
        $since = isset($_POST['since']) ? sanitize_text_field(wp_unslash((string) $_POST['since'])) : '';
        $since = preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1 ? $since : null;

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $file['tmp_name'])
            || (int) $file['size'] > 50 * MB_IN_BYTES) {
            $message = __('No file arrived (or it is larger than 50 MB).', 'taxmail');
        } else {
            try {
                $proposals = (new AmazonOrderHistory($since, $scope === 'all' ? null : AmazonOrderHistory::TECHNICAL))
                    ->read((string) file_get_contents((string) $file['tmp_name']));
                $message = ExportQueue::store($proposals)
                    /* translators: %d: number of orders */
                    ? sprintf(__('%d orders read; each fetch hands the next 100 to the inbox.', 'taxmail'), count($proposals))
                    : __('The orders could not be stored under wp-content.', 'taxmail');
            } catch (\InvalidArgumentException $failure) {
                /* translators: %s: why */
                $message = sprintf(__('That is not the file: %s', 'taxmail'), $failure->getMessage());
            }
        }

        set_transient(self::EXPORT_DONE . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS);
        $this->back();
    }

    public function handleExportDelete(): void
    {
        $this->guard('taxmail_export_delete');
        ExportQueue::clear();
        $this->back();
    }

    /** The upload form, empty: its fields stand in the «Mail» tab and name it (attribute «form»). */
    public static function exportForm(): void
    {
        // Read-only look at which screen this is; nothing is acted on.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        if ($page !== 'taxmod-settings' || $tab !== self::TAB || ! current_user_can(self::CAPABILITY)) {
            return;
        }

        echo '<form id="' . esc_attr(self::EXPORT_FORM) . '" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxmail_export">' . wp_nonce_field('taxmail_export', '_wpnonce', true, false) . '</form>';
    }

    /**
     * «Test connection» (D-981): the modeller calls it after saving what was just entered and shows the sentence. It
     * logs in, creates the missing folders and says what it found.
     */
    public static function test(): string
    {
        $credentials = Settings::credentials();

        if ($credentials === null) {
            return __('No mailbox yet — enter server, user and password, then test again.', 'taxmail');
        }

        try {
            $client = new ImapClient(new TlsSocketStream($credentials['host'], $credentials['port']));
            $client->login($credentials['user'], $credentials['password']);
            $settings = Settings::load();
            $check = (new ConnectionCheck($settings->folders, $settings->rules->folders($settings->folders)))->run($client);
            $client->logout();
        } catch (ImapException $failure) {
            /* translators: %s: the server's answer */
            return sprintf(__('Connection failed: %s', 'taxmail'), $failure->getMessage());
        }

        $sentences = [
            /* translators: %d: number of mails in the inbox */
            sprintf(__('Connected. %d mails in the inbox.', 'taxmail'), $check['inbox_count']),
            $check['can_move'] ? __('The server can move mails.', 'taxmail') : __('The server can neither MOVE nor UIDPLUS — mails cannot be sorted into folders.', 'taxmail'),
        ];

        if ($check['created'] !== []) {
            /* translators: %s: folder names */
            $sentences[] = sprintf(__('Folders created: %s.', 'taxmail'), implode(', ', $check['created']));
        }

        return implode(' ', $sentences);
    }

    /**
     * 0.1.x kept «how often» in its own settings; the modeller owns that field now (D-981). His choice is carried over
     * once, «never» as «off», so updating changes nothing he set.
     */
    public static function moveIntervalOnce(): void
    {
        if (get_option(self::INTERVAL_MOVED) !== false) {
            return;
        }

        $stored = get_option(Settings::OPTION, []);
        $schedule = is_array($stored) && isset($stored['schedule']) ? (string) $stored['schedule'] : null;

        if ($schedule !== null && $schedule !== 'daily') {
            FetchInterval::save(MailSource::KEY, $schedule === '' ? 'off' : $schedule);
        }

        update_option(self::INTERVAL_MOVED, self::VERSION, false);
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
