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
        $html = '<div class="wrap"><h1>' . esc_html__('Mail import', 'taxmail') . '</h1>';

        if (isset($_GET['saved'])) {
            $html .= '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'taxmail') . '</p></div>';
        }

        if (! class_exists('Taxmod\\WordPress\\Plugin')) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('The Taxonomy Modeller is not active — mails can be fetched, but nothing can be written.', 'taxmail') . '</p></div>';
        }

        $html .= $this->connection($check);

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="taxmail_save">' . wp_nonce_field('taxmail_save', '_wpnonce', true, false);

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
                    . '</td></tr>';
            }

            $html .= '</tbody></table>';
        }

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

    /** @param array<string, mixed>|null $check */
    private function connection(?array $check): string
    {
        $html = '<h2>' . esc_html__('Connection', 'taxmail') . '</h2>';

        if (Settings::credentials() === null) {
            $html .= '<p>' . esc_html__('Add the mailbox to wp-config.php:', 'taxmail') . '</p>'
                . '<pre>define( \'TAXMAIL_IMAP_HOST\', \'imap.example.com\' );' . "\n"
                . 'define( \'TAXMAIL_IMAP_PORT\', 993 );' . "\n"
                . 'define( \'TAXMAIL_IMAP_USER\', \'einkauf@example.com\' );' . "\n"
                . 'define( \'TAXMAIL_IMAP_PASSWORD\', \'…\' );</pre>';
        }

        if ($check !== null) {
            $html .= $check['ok'] ? $this->success($check) : $this->failure($check);
        }

        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxmail_check">' . wp_nonce_field('taxmail_check', '_wpnonce', true, false)
            . get_submit_button(__('Test connection', 'taxmail'), 'secondary', 'submit', false)
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
            ? esc_html__('No mailbox configured in wp-config.php.', 'taxmail')
            /* translators: %s: the server's answer */
            : sprintf(esc_html__('Connection failed: %s', 'taxmail'), esc_html((string) ($check['detail'] ?? '')));

        return '<div class="notice notice-error inline"><p>' . $text . '</p></div>';
    }
}
