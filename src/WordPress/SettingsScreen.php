<?php declare(strict_types=1);

namespace TaxMail\WordPress;

/**
 * The settings page: parsers on/off, folder names, and the connection test. Returns its HTML (CD-8).
 */
final class SettingsScreen
{
    /** @param array<string, mixed>|null $check the last connection test; `mixed` because its fields differ by outcome */
    public function render(Settings $settings, ?array $check): string
    {
        $registry = Plugin::registry($settings);
        $html = '<div class="wrap"><h1>' . esc_html__('Mail import', 'taxmail') . ' <small>' . esc_html(Plugin::VERSION) . '</small></h1>';

        if (isset($_GET['saved'])) {
            $html .= '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'taxmail') . '</p></div>';
        }

        if (! class_exists('Taxmod\\WordPress\\Plugin')) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('The Taxonomy Modeller is not active — mails can be fetched, but nothing can be written.', 'taxmail') . '</p></div>';
        }

        $html .= $this->connection($check);

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="taxmail_save">' . wp_nonce_field('taxmail_save', '_wpnonce', true, false);

        $html .= $this->account();

        $html .= '<h2>' . esc_html__('Mail connections', 'taxmail') . '</h2>';

        if ($registry->all() === []) {
            $html .= '<p>' . esc_html__('No parser is installed yet.', 'taxmail') . '</p>';
        } else {
            $html .= '<table class="widefat striped" style="max-width:40em"><caption class="screen-reader-text">'
                . esc_html__('Mail connections', 'taxmail') . '</caption><tbody>';

            foreach ($registry->all() as $key => $parser) {
                $blocked = $registry->isBlocked($key);
                $html .= '<tr><td><label><input type="checkbox" name="parsers[' . esc_attr($key) . ']" value="1"'
                    . checked($settings->parsers[$key] ?? false, true, false) . disabled($blocked, true, false) . '> '
                    . esc_html($parser->shopName()) . '</label></td><td>'
                    . ($blocked ? esc_html__('off — another module handles this shop', 'taxmail') : '')
                    . ($registry->isStatusOnly($key) ? esc_html__('status only — another module brings the orders', 'taxmail') : '')
                    . '</td></tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '<h2>' . esc_html__('Automatic fetch', 'taxmail') . '</h2><p><label for="taxmail-schedule">'
            . esc_html__('Fetch new mails', 'taxmail') . '</label> <select id="taxmail-schedule" name="schedule">';

        foreach ([
            ''           => __('never (button only)', 'taxmail'),
            'hourly'     => __('every hour', 'taxmail'),
            'twicedaily' => __('twice a day', 'taxmail'),
            'daily'      => __('once a day', 'taxmail'),
        ] as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected($settings->schedule, $value, false) . '>' . esc_html($label) . '</option>';
        }

        $html .= '</select></p>';

        $html .= $this->rules($settings);

        $html .= '<h2>' . esc_html__('Folders', 'taxmail') . '</h2><table class="form-table" role="presentation">';

        foreach ([
            'inbox'        => [__('Inbox', 'taxmail'), $settings->folders->inbox],
            'imported'     => [__('Imported', 'taxmail'), $settings->folders->imported],
            'discarded'    => [__('Discarded', 'taxmail'), $settings->folders->discarded],
            'unrecognised' => [__('Not recognised', 'taxmail'), $settings->folders->unrecognised],
        ] as $key => [$label, $value]) {
            $html .= '<tr><th scope="row"><label for="taxmail-folder-' . esc_attr($key) . '">' . esc_html($label) . '</label></th>'
                . '<td><input type="text" class="regular-text" id="taxmail-folder-' . esc_attr($key) . '" name="folders[' . esc_attr($key) . ']" value="' . esc_attr($value) . '"></td></tr>';
        }

        $html .= '</table>' . get_submit_button(__('Save', 'taxmail')) . '</form></div>';

        return $html;
    }

    /** The sorting rules (D-949) as one line each, and beside them the senders counted in the inbox. */
    private function rules(Settings $settings): string
    {
        $html = '<h2>' . esc_html__('Sorting rules', 'taxmail') . '</h2><p>'
            . esc_html__('One rule per line: sender | subject | folder. Sender and subject are pieces of text, one of them may stay empty. «Verworfen» and «Nicht erkannt» mean the folders below; any other name is a folder of your own, created when needed. Rules only sort mails that are no order; shop mails without an order (advertising, your own sales) go to «Verworfen» anyway. Nothing is ever deleted.', 'taxmail')
            . '</p><p><label for="taxmail-rules" class="screen-reader-text">' . esc_html__('Sorting rules', 'taxmail') . '</label>'
            . '<textarea id="taxmail-rules" name="rules" rows="8" class="large-text code" placeholder="newsletter@shop.example | | Verworfen&#10;| Ihr Paket | Taxmod/Versand">'
            . esc_textarea($settings->rules->toText()) . '</textarea></p>';

        $senders = get_transient(Plugin::SENDERS . get_current_user_id());

        if (is_array($senders) && $senders !== []) {
            $html .= '<details open><summary>' . esc_html__('Most frequent senders in the inbox', 'taxmail') . '</summary>'
                . '<table class="widefat striped" style="max-width:40em"><thead><tr><th scope="col">' . esc_html__('Sender', 'taxmail')
                . '</th><th scope="col">' . esc_html__('Mails', 'taxmail') . '</th><th scope="col">' . esc_html__('Rule', 'taxmail') . '</th></tr></thead><tbody>';

            foreach ($senders as $sender => $count) {
                $ruled = $settings->rules->folderFor((string) $sender, '');
                $html .= '<tr><td><code>' . esc_html((string) $sender) . '</code></td><td>' . esc_html((string) $count) . '</td><td>'
                    . esc_html($ruled ?? '') . '</td></tr>';
            }

            $html .= '</tbody></table></details>';
        }

        return $html;
    }

    private function account(): string
    {
        $html = '<h2>' . esc_html__('Mailbox', 'taxmail') . '</h2>';

        if (Settings::accountInConfig()) {
            return $html . '<p>' . esc_html__('The mailbox is set in wp-config.php (TAXMAIL_IMAP_* constants).', 'taxmail') . '</p>';
        }

        $account = Settings::storedAccount();
        $passwordNote = match (true) {
            $account['sealed'] === '' => __('No password stored yet.', 'taxmail'),
            $account['opens']         => __('A password is stored encrypted. Leave empty to keep it.', 'taxmail'),
            default                   => __('The stored password can no longer be decrypted (the WordPress salts changed). Enter it again.', 'taxmail'),
        };

        $html .= '<table class="form-table" role="presentation">';

        foreach ([
            'host' => [__('IMAP server', 'taxmail'), 'text', $account['host'], 'imap.example.com'],
            'port' => [__('Port (SSL)', 'taxmail'), 'number', (string) $account['port'], '993'],
            'user' => [__('User', 'taxmail'), 'text', $account['user'], 'einkauf@example.com'],
        ] as $key => [$label, $type, $value, $placeholder]) {
            $html .= '<tr><th scope="row"><label for="taxmail-account-' . esc_attr($key) . '">' . esc_html($label) . '</label></th>'
                . '<td><input type="' . esc_attr($type) . '" class="regular-text" id="taxmail-account-' . esc_attr($key) . '" name="account[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" autocomplete="off"></td></tr>';
        }

        $html .= '<tr><th scope="row"><label for="taxmail-account-password">' . esc_html__('Password', 'taxmail') . '</label></th>'
            . '<td><input type="password" class="regular-text" id="taxmail-account-password" name="account[password]" value="" autocomplete="new-password">'
            . '<p class="description">' . esc_html($passwordNote) . '</p></td></tr></table>';

        return $html;
    }

    /** @param array<string, mixed>|null $check */
    private function connection(?array $check): string
    {
        $html = '<h2>' . esc_html__('Connection', 'taxmail') . '</h2>';

        if ($check !== null) {
            $html .= $check['ok'] ? $this->success($check) : $this->failure($check);
        }

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxmail_check">' . wp_nonce_field('taxmail_check', '_wpnonce', true, false)
            . get_submit_button(__('Test connection', 'taxmail'), 'secondary', 'submit', false)
            . '</form>';

        $html .= '<h2>' . esc_html__('Backlog', 'taxmail') . '</h2><p>'
            . esc_html__('Reads the mails from before the starting point: a mail whose order is in the model already moves to «Imported», a mail a parser can read becomes a proposal, the sorting rules sort the rest, and what no rule names stays in the inbox. Each click works for about 20 seconds; click again until nothing is left.', 'taxmail')
            . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxmail_backlog">' . wp_nonce_field('taxmail_backlog', '_wpnonce', true, false)
            . '<p><label><input type="checkbox" name="from_start" value="1"> ' . esc_html__('Start again from the oldest mail (after a parser was switched on or fixed)', 'taxmail') . '</label></p>'
            . get_submit_button(__('Sort backlog', 'taxmail'), 'secondary', 'submit', false)
            . '</form>';

        $html .= '<h2>' . esc_html__('Senders', 'taxmail') . '</h2><p>'
            . esc_html__('Counts who sent the mails in the inbox, as a help for the sorting rules. Nothing is moved.', 'taxmail')
            . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxmail_senders">' . wp_nonce_field('taxmail_senders', '_wpnonce', true, false)
            . get_submit_button(__('Count senders', 'taxmail'), 'secondary', 'submit', false)
            . '</form>';

        $html .= '<h2>' . esc_html__('Start over', 'taxmail') . '</h2><p>'
            . esc_html__('Moves the mails in «Not recognised» and «Discarded» back to the inbox, forgets every proposal not taken over, and from then on reads only mails that arrive later. Taken-over orders and the «Imported» folder stay.', 'taxmail')
            . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxmail_restart">' . wp_nonce_field('taxmail_restart', '_wpnonce', true, false)
            . get_submit_button(__('Start over from now', 'taxmail'), 'secondary', 'submit', false, ['onclick' => "return confirm(this.form.previousElementSibling.textContent)"])
            . '</form>';

        return $html;
    }

    /** @param array<string, mixed> $check */
    private function success(array $check): string
    {
        $lines = [
            sprintf(
                /* translators: %d: number of mails in the inbox */
                esc_html__('Connected. %d mails in the inbox.', 'taxmail'),
                (int) $check['inbox_count']
            ),
            $check['can_move']
                ? esc_html__('The server can move mails.', 'taxmail')
                : esc_html__('The server can neither MOVE nor UIDPLUS — mails cannot be sorted into folders.', 'taxmail'),
        ];

        foreach ((array) $check['created'] as $folder) {
            /* translators: %s: folder name */
            $lines[] = sprintf(esc_html__('Folder created: %s', 'taxmail'), esc_html((string) $folder));
        }

        foreach ((array) $check['present'] as $folder) {
            /* translators: %s: folder name */
            $lines[] = sprintf(esc_html__('Folder present: %s', 'taxmail'), esc_html((string) $folder));
        }

        return '<div class="notice notice-' . ($check['can_move'] ? 'success' : 'warning') . ' inline"><p>' . implode('<br>', $lines) . '</p></div>';
    }

    /** @param array<string, mixed> $check */
    private function failure(array $check): string
    {
        $text = $check['error'] === 'no_credentials'
            ? esc_html__('No mailbox configured yet — fill in the mailbox fields below and save.', 'taxmail')
            /* translators: %s: the server's answer */
            : sprintf(esc_html__('Connection failed: %s', 'taxmail'), esc_html((string) ($check['detail'] ?? '')));

        return '<div class="notice notice-error inline"><p>' . $text . '</p></div>';
    }
}
