<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use TaxMail\Core\Imap\ImapClient;
use TaxMail\Core\Imap\ImapException;
use TaxMail\Core\Imap\TlsSocketStream;
use TaxMail\Core\Mailbox\ConnectionCheck;
use TaxMail\Core\Mailbox\FolderPlan;
use TaxMail\Core\Parser\OrderMailParser;
use TaxMail\Core\Parser\ParserRegistry;
use TaxMail\Core\Proposal\OrderProposal;
use TaxMail\Core\Proposal\ProposalKind;
use TaxMail\Core\Write\ChangeListBuilder;
use TaxMail\Core\Write\ExistingOrder;
use TaxMail\Core\Write\WriteException;
use WP_Error;

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

    public const SETTINGS_PAGE = 'taxmail-settings';
    public const CRON_HOOK     = 'taxmail_fetch_cron';

    /** The last failed automatic fetch, shown on the review page — the cron has nobody else to tell. */
    public const CRON_FAILURE = 'taxmail_cron_failure';

    /** Messages for the next page view of one user, after a redirect. */
    private const NOTICES = 'taxmail_notices_';

    public static function boot(string $file): void
    {
        $plugin = new self();

        register_activation_hook($file, $plugin->activate(...));
        register_deactivation_hook($file, static fn () => wp_clear_scheduled_hook(self::CRON_HOOK));

        add_action('admin_init', Schema::upgrade(...));
        add_action('admin_init', self::schedule(...));
        add_action('admin_menu', $plugin->registerMenu(...), 20);
        add_action(self::CRON_HOOK, $plugin->runCron(...));
        add_action('admin_post_taxmail_save', $plugin->handleSave(...));
        add_action('admin_post_taxmail_check', $plugin->handleCheck(...));
        add_action('admin_post_taxmail_fetch', $plugin->handleFetch(...));
        add_action('admin_post_taxmail_take', $plugin->handleTake(...));
        add_action('admin_post_taxmail_discard', $plugin->handleDiscard(...));
    }

    public function activate(): void
    {
        Schema::upgrade();
        self::schedule();
    }

    /** Daily unless he picks otherwise in the settings; the button fetches on demand (his word, 2026-10-20). */
    public static function schedule(): void
    {
        $wanted = Settings::load()->schedule;

        if ((wp_get_schedule(self::CRON_HOOK) ?: '') === $wanted) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);

        if ($wanted !== '') {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $wanted, self::CRON_HOOK);
        }
    }

    /** WP-Cron runs without a user: it may fetch and store, never write to the model (that needs his pick anyway). */
    public function runCron(): void
    {
        if (Settings::credentials() === null) {
            return;
        }

        try {
            (new Mailbox(Settings::load()))->fetch();
            delete_option(self::CRON_FAILURE);
        } catch (ImapException $failure) {
            update_option(self::CRON_FAILURE, current_time('mysql') . ' — ' . $failure->getMessage(), false);
        }
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
        // Under the modeller's menu when it is there; the module is useless without it, but the pages still say so.
        $parent = isset($GLOBALS['admin_page_hooks']['taxmod']) ? 'taxmod' : 'options-general.php';

        add_submenu_page(
            $parent,
            __('Review incoming orders', 'taxmail'),
            __('Mail import', 'taxmail'),
            self::CAPABILITY,
            self::PAGE,
            function (): void {
                $notices = array_map('esc_html', $this->takeNotices());
                $cronFailure = (string) get_option(self::CRON_FAILURE, '');

                if ($cronFailure !== '') {
                    /* translators: %s: time and reason */
                    $notices[] = esc_html(sprintf(__('Last automatic fetch failed: %s', 'taxmail'), $cronFailure));
                }

                $model = ModelGateway::available() ? new ModelGateway(Targets::load()) : null;
                print (new ReviewScreen(new ProposalTable(), $model))->render($notices);
            }
        );

        add_submenu_page(
            $parent,
            __('Mail import settings', 'taxmail'),
            __('Mail import settings', 'taxmail'),
            self::CAPABILITY,
            self::SETTINGS_PAGE,
            static function (): void {
                $user = get_current_user_id();
                $result = get_transient(self::CHECK_RESULT . $user);
                delete_transient(self::CHECK_RESULT . $user);

                print (new SettingsScreen())->render(Settings::load(), is_array($result) ? $result : null);
            }
        );
    }

    public function handleFetch(): void
    {
        $this->guard('taxmail_fetch');

        try {
            $counts = (new Mailbox(Settings::load()))->fetch();
            $this->notice(sprintf(
                /* translators: 1: mails read, 2: new proposals or rows, 3: mails moved to a folder */
                __('%1$d mails read, %2$d stored, %3$d sorted into folders.', 'taxmail'),
                $counts['fetched'],
                $counts['stored'],
                $counts['moved']
            ));
        } catch (ImapException $failure) {
            /* translators: %s: the server's answer */
            $this->notice(sprintf(__('Fetching failed: %s', 'taxmail'), $failure->getMessage()));
        }

        $this->back(self::PAGE, []);
    }

    public function handleTake(): void
    {
        $row = $this->reviewedRow();
        $proposal = OrderProposal::fromArray((array) json_decode((string) $row['proposal'], true));
        $model = new ModelGateway(Targets::load());
        $builder = new ChangeListBuilder(Targets::load());

        try {
            $changes = match ($proposal->kind) {
                ProposalKind::Ordered   => $builder->newOrder($proposal, $this->pickedLines(count($proposal->lines)), self::originNote($row, $proposal)),
                ProposalKind::Shipped   => $builder->shipped($this->existing($model, $proposal), $proposal->trackingNumbers),
                ProposalKind::Delivered => $builder->delivered($this->existing($model, $proposal), substr((string) ($row['received_at'] ?? current_time('mysql')), 0, 10)),
                default                 => throw new WriteException('this kind of mail cannot be taken over'),
            };
        } catch (WriteException $refusal) {
            /* translators: %s: reason */
            $this->notice(sprintf(__('Not taken: %s', 'taxmail'), $refusal->getMessage()));
            $this->back(self::PAGE, []);
        }

        $written = $changes === [] ? [] : $model->write($changes);

        if ($written instanceof WP_Error) {
            /* translators: %s: the modeller's answer */
            $this->notice(sprintf(__('Nothing was written: %s', 'taxmail'), $written->get_error_message()));
            $this->back(self::PAGE, []);
        }

        (new ProposalTable())->close((int) $row['id'], ProposalTable::IMPORTED, $written);
        $this->notice(sprintf(
            /* translators: 1: platform, 2: order number */
            __('%1$s %2$s taken over.', 'taxmail'),
            $proposal->platform,
            $proposal->orderNumber
        ));
        $this->moveMail($row, 'imported');
        $this->back(self::PAGE, []);
    }

    public function handleDiscard(): void
    {
        $row = $this->reviewedRow();
        (new ProposalTable())->close((int) $row['id'], ProposalTable::DISCARDED);
        $this->moveMail($row, 'discarded');
        $this->back(self::PAGE, []);
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

        $schedule = isset($_POST['schedule']) ? sanitize_key(wp_unslash($_POST['schedule'])) : 'daily';

        (new Settings($parsers, new FolderPlan(
            $folder('inbox', $defaults->inbox),
            $folder('imported', $defaults->imported),
            $folder('discarded', $defaults->discarded),
            $folder('unrecognised', $defaults->unrecognised),
        ), in_array($schedule, Settings::SCHEDULES, true) ? $schedule : 'daily'))->save();
        self::schedule();

        $this->back(self::SETTINGS_PAGE, ['saved' => '1']);
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
        $this->back(self::SETTINGS_PAGE, []);
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
    private function back(string $page, array $arguments): never
    {
        wp_safe_redirect(add_query_arg($arguments, admin_url('admin.php?page=' . $page)));
        exit;
    }

    /**
     * Capability, nonce of exactly this proposal, and the row must still be open — a second click does nothing.
     *
     * @return array<string, mixed>
     */
    private function reviewedRow(): array
    {
        $id = isset($_POST['proposal']) ? absint(wp_unslash($_POST['proposal'])) : 0;
        $this->guard('taxmail_review_' . $id);
        $row = (new ProposalTable())->find($id);

        if ($row === null || $row['status'] !== ProposalTable::OPEN) {
            $this->notice(__('This proposal was already handled.', 'taxmail'));
            $this->back(self::PAGE, []);
        }

        return $row;
    }

    /** @return list<int> */
    private function pickedLines(int $lineCount): array
    {
        $posted = isset($_POST['lines']) && is_array($_POST['lines']) ? array_map('absint', wp_unslash($_POST['lines'])) : [];

        return array_values(array_filter(array_unique($posted), static fn (int $index): bool => $index < $lineCount));
    }

    private function existing(ModelGateway $model, OrderProposal $proposal): ExistingOrder
    {
        $order = $model->findOrder($proposal->platform, $proposal->orderNumber);

        if ($order instanceof WP_Error) {
            throw new WriteException($order->get_error_message());
        }

        return $order ?? throw new WriteException('the order is not in the model');
    }

    /** @param array<string, mixed> $row */
    private function moveMail(array $row, string $folderKey): void
    {
        try {
            (new Mailbox(Settings::load()))->moveHandled($row, $folderKey);
        } catch (ImapException $failure) {
            /* translators: %s: the server's answer */
            $this->notice(sprintf(__('Saved, but the mail could not be moved: %s', 'taxmail'), $failure->getMessage()));
        }
    }

    /** @param array<string, mixed> $row */
    private static function originNote(array $row, OrderProposal $proposal): string
    {
        return sprintf(
            /* translators: 1: platform, 2: mail subject, 3: date */
            __('From %1$s mail «%2$s», taken over %3$s.', 'taxmail'),
            $proposal->platform,
            (string) $row['subject'],
            current_time('Y-m-d')
        );
    }

    private function notice(string $text): void
    {
        $key = self::NOTICES . get_current_user_id();
        $notices = get_transient($key);
        $notices = is_array($notices) ? $notices : [];
        $notices[] = $text;
        set_transient($key, $notices, 10 * MINUTE_IN_SECONDS);
    }

    /** @return list<string> */
    private function takeNotices(): array
    {
        $key = self::NOTICES . get_current_user_id();
        $notices = get_transient($key);
        delete_transient($key);

        return is_array($notices) ? array_values(array_map('strval', $notices)) : [];
    }

    private static function folderName(mixed $value, string $fallback): string
    {
        $name = trim(sanitize_text_field(is_string($value) ? $value : ''));

        return $name === '' ? $fallback : $name;
    }
}
