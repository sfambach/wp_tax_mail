<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Mailbox\ConnectionCheck;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\ParserRegistry;

/**
 * The boundary: menu, settings, the connection test. It wires WordPress to the core and decides nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md
 */
final class Plugin
{
    public const VERSION     = '0.0.1';
    public const TEXT_DOMAIN = 'taxmail';
    public const CAPABILITY  = 'manage_options';
    public const PAGE        = 'taxmail';

    /** Where the last connection test's outcome waits for the redirect — per user, ten minutes. */
    private const CHECK_RESULT = 'taxmail_check_';

    public static function boot(string $file): void
    {
        $plugin = new self();

        add_action('admin_menu', $plugin->registerMenu(...), 20);
        add_action('admin_post_taxmail_save', $plugin->handleSave(...));
        add_action('admin_post_taxmail_check', $plugin->handleCheck(...));
    }

    /**
     * Every parser another plugin or this one announces. A mail connection is «a kind of plugin» (D-942): adding one
     * means hooking this filter, nothing in here changes.
     */
    public static function registry(Settings $settings): ParserRegistry
    {
        $parsers = array_values(array_filter(
            (array) apply_filters('taxmail_parsers', []),
            static fn (mixed $parser): bool => $parser instanceof OrderMailParser
        ));

        // tax_ebay adds «ebay» here while it is active, so its API and this mail parser never both write an eBay order.
        $blocked = array_values(array_map('strval', (array) apply_filters('taxmail_blocked_parsers', [])));

        return new ParserRegistry($parsers, $settings->parsers, $blocked);
    }

    public function registerMenu(): void
    {
        // Under the modeller's menu when it is there; the module is useless without it, but the page still says so.
        $parent = isset($GLOBALS['admin_page_hooks']['taxmod']) ? 'taxmod' : 'options-general.php';

        add_submenu_page(
            $parent,
            __('Mail import', 'taxmail'),
            __('Mail import', 'taxmail'),
            self::CAPABILITY,
            self::PAGE,
            static function (): void {
                $user = get_current_user_id();
                $result = get_transient(self::CHECK_RESULT . $user);
                delete_transient(self::CHECK_RESULT . $user);

                print (new SettingsScreen())->render(Settings::load(), is_array($result) ? $result : null);
            }
        );
    }

    public function handleSave(): void
    {
        $this->guard('taxmail_save');

        $parsers = [];
        $posted = isset($_POST['parsers']) && is_array($_POST['parsers']) ? wp_unslash($_POST['parsers']) : [];
        $settings = Settings::load();

        foreach (array_keys(self::registry($settings)->all()) as $key) {
            $parsers[$key] = isset($posted[$key]);
        }

        $folders = isset($_POST['folders']) && is_array($_POST['folders']) ? wp_unslash($_POST['folders']) : [];
        $folder = static fn (string $key, string $fallback): string => self::folderName($folders[$key] ?? '', $fallback);
        $defaults = $settings->folders;

        (new Settings($parsers, new FolderPlan(
            $folder('inbox', $defaults->inbox),
            $folder('imported', $defaults->imported),
            $folder('discarded', $defaults->discarded),
            $folder('unrecognised', $defaults->unrecognised),
        )))->save();

        $this->back(['saved' => '1']);
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
                $result = ['ok' => true] + (new ConnectionCheck(Settings::load()->folders))->run($client);
                $client->logout();
            } catch (ImapException $failure) {
                $result = ['ok' => false, 'error' => 'imap', 'detail' => $failure->getMessage()];
            }
        }

        set_transient(self::CHECK_RESULT . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        $this->back([]);
    }

    /** CD-5, in this order: capability, then nonce. */
    private function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You may not change the mail import.', 'taxmail'), 403);
        }

        check_admin_referer($action);
    }

    /** @param array<string, string> $arguments */
    private function back(array $arguments): never
    {
        wp_safe_redirect(add_query_arg($arguments, menu_page_url(self::PAGE, false) ?: admin_url('admin.php?page=' . self::PAGE)));
        exit;
    }

    private static function folderName(mixed $value, string $fallback): string
    {
        $name = trim(sanitize_text_field(is_string($value) ? $value : ''));

        return $name === '' ? $fallback : $name;
    }
}
