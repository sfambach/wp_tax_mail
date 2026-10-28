<?php declare(strict_types=1);

namespace TaxMail\WordPress;

/**
 * The «Mail» tab on the modeller's Configuration page (D-945). The page draws one order for every module (D-981): the
 * mailbox fields from account() under «Access» with Save, «Test connection» and «Delete access data», then the fetch
 * interval, then render() — parsers on/off, sorting rules, folder names. «Count senders» is a link, since a form cannot
 * hold another. Fetching, the backlog, the search and starting over are buttons in the modeller's inbox (D-953).
 * Returns its HTML (CD-8).
 */
final class SettingsScreen
{
    public function render(Settings $settings, string $sendersFailure, string $inboxUrl): string
    {
        $registry = Plugin::registry($settings);
        // Title and version are drawn by the Configuration page (D-968).
        $html = '';
        $failure = (string) get_option(MailSource::LAST_FAILURE, '');

        if ($failure !== '') {
            /* translators: %s: time and reason */
            $html .= '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('Last problem: %s', 'taxmail'), $failure)) . '</p></div>';
        }

        $html .= '<p>' . sprintf(
            /* translators: %s: link to the modeller's inbox */
            esc_html__('Proposals, fetching, the backlog, «fetch an order» and starting over are on the page %s of the Taxonomy Modeller.', 'taxmail'),
            '<a href="' . esc_url($inboxUrl) . '">' . esc_html__('Inbox', 'taxmod') . '</a>'
        ) . '</p>';

        $html .= '<h2>' . esc_html__('Mail connections', 'taxmail') . '</h2>';

        if ($registry->all() === []) {
            $html .= '<p>' . esc_html__('No parser is installed yet.', 'taxmail') . '</p>';
        } else {
            $html .= '<table class="widefat striped" style="max-width:40em"><caption class="screen-reader-text">'
                . esc_html__('Mail connections', 'taxmail') . '</caption><tbody>';

            foreach ($registry->all() as $key => $parser) {
                $html .= '<tr><td><label><input type="checkbox" name="parsers[' . esc_attr($key) . ']" value="1"'
                    . checked($settings->parsers[$key] ?? false, true, false) . '> '
                    . esc_html($parser->shopName()) . '</label></td></tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= $this->rules($settings, $sendersFailure);
        $html .= self::export();

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

        return $html . '</table>';
    }

    /** The sorting rules (D-949) as one line each, and beside them the senders counted in the inbox. */
    private function rules(Settings $settings, string $sendersFailure): string
    {
        $html = '<h2>' . esc_html__('Sorting rules', 'taxmail') . '</h2><p>'
            . esc_html__('One rule per line: sender | subject | folder. Sender and subject are pieces of text, one of them may stay empty. «Verworfen» and «Nicht erkannt» mean the folders below; any other name is a folder of your own, created when needed. Rules only sort mails that are no order; shop mails without an order (advertising, your own sales) go to «Verworfen» anyway. Nothing is ever deleted.', 'taxmail')
            . '</p><p><label for="taxmail-rules" class="screen-reader-text">' . esc_html__('Sorting rules', 'taxmail') . '</label>'
            . '<textarea id="taxmail-rules" name="rules" rows="8" class="large-text code" placeholder="newsletter@shop.example | | Verworfen&#10;| Ihr Paket | Taxmod/Versand">'
            . esc_textarea($settings->rules->toText()) . '</textarea></p>';

        $html .= '<p>' . esc_html__('«Count senders» counts who sent the mails in the inbox, as a help for the rules. Nothing is moved.', 'taxmail')
            . ' ' . self::actionLink('taxmail_senders', __('Count senders', 'taxmail')) . '</p>';

        if ($sendersFailure !== '') {
            /* translators: %s: the server's answer */
            $html .= '<div class="notice notice-error inline"><p>' . esc_html(sprintf(__('Connection failed: %s', 'taxmail'), $sendersFailure)) . '</p></div>';
        }

        $done = get_transient(Plugin::SAMPLES_DONE . get_current_user_id());
        delete_transient(Plugin::SAMPLES_DONE . get_current_user_id());

        if (is_string($done)) {
            $html .= '<div class="notice notice-success inline"><p>' . esc_html($done) . '</p></div>';
        }

        if (SampleMails::count() > 0) {
            /* translators: 1: number of files, 2: folder */
            $html .= '<p>' . esc_html(sprintf(__('%1$d sample mails lie in wp-content/%2$s for writing a parser; the folder answers no web request.', 'taxmail'), SampleMails::count(), SampleMails::FOLDER))
                . ' ' . self::actionLink('taxmail_samples_delete', __('Delete samples', 'taxmail')) . '</p>';
        }

        $senders = get_transient(Plugin::SENDERS . get_current_user_id());

        if (is_array($senders) && $senders !== []) {
            $html .= '<details open><summary>' . esc_html__('Most frequent senders in the inbox', 'taxmail') . '</summary>'
                . '<table class="widefat striped" style="max-width:40em"><thead><tr><th scope="col">' . esc_html__('Sender', 'taxmail')
                . '</th><th scope="col">' . esc_html__('Mails', 'taxmail') . '</th><th scope="col">' . esc_html__('Rule', 'taxmail') . '</th><th scope="col"><span class="screen-reader-text">'
                . esc_html__('Samples', 'taxmail') . '</span></th></tr></thead><tbody>';

            foreach ($senders as $sender => $count) {
                $ruled = $settings->rules->folderFor((string) $sender, '');
                $html .= '<tr><td><code>' . esc_html((string) $sender) . '</code></td><td>' . esc_html((string) $count) . '</td><td>'
                    . esc_html($ruled ?? '') . '</td><td>' . self::actionLink('taxmail_samples', __('Save samples', 'taxmail'), ['sender' => (string) $sender]) . '</td></tr>';
            }

            $html .= '</tbody></table></details>';
        }

        return $html;
    }

    /**
     * Amazon's «Order History.csv» for the orders from before the parser. The fields belong to the form Plugin::exportForm()
     * prints after the page's own one (attribute «form»), since a form cannot hold another.
     */
    private static function export(): string
    {
        $html = '<h2>' . esc_html__('Amazon order history', 'taxmail') . '</h2><p>'
            . esc_html__('For the orders from before the mails: in Amazon, «Meine Daten anfordern», then upload «Order History.csv» from the archive. Each fetch hands the next 100 orders to the inbox; cancelled orders are left out. Addresses and payment data in the file are not kept.', 'taxmail')
            . '</p>';
        $done = get_transient(Plugin::EXPORT_DONE . get_current_user_id());
        delete_transient(Plugin::EXPORT_DONE . get_current_user_id());

        if (is_string($done)) {
            $html .= '<div class="notice notice-info inline"><p>' . esc_html($done) . '</p></div>';
        }

        $progress = ExportQueue::progress();

        if ($progress['total'] > 0) {
            /* translators: 1: orders handed to the inbox, 2: orders in the upload */
            $html .= '<p>' . esc_html(sprintf(__('%1$d of %2$d orders handed to the inbox.', 'taxmail'), $progress['handed'], $progress['total']))
                . ' ' . self::actionLink('taxmail_export_delete', __('Forget the upload', 'taxmail')) . '</p>';
        }

        return $html . '<table class="form-table" role="presentation">'
            . '<tr><th scope="row"><label for="taxmail-export-file">' . esc_html__('File', 'taxmail') . '</label></th>'
            . '<td><input type="file" id="taxmail-export-file" name="export" accept=".csv,text/csv" form="' . esc_attr(Plugin::EXPORT_FORM) . '"></td></tr>'
            . '<tr><th scope="row"><label for="taxmail-export-scope">' . esc_html__('Which orders', 'taxmail') . '</label></th>'
            . '<td><select id="taxmail-export-scope" name="scope" form="' . esc_attr(Plugin::EXPORT_FORM) . '">'
            . '<option value="technical">' . esc_html__('Only technical (by department and product name: electronics, cables, tools …)', 'taxmail') . '</option>'
            . '<option value="all">' . esc_html__('All', 'taxmail') . '</option></select></td></tr>'
            . '<tr><th scope="row"><label for="taxmail-export-since">' . esc_html__('Ordered from', 'taxmail') . '</label></th>'
            . '<td><input type="date" id="taxmail-export-since" name="since" form="' . esc_attr(Plugin::EXPORT_FORM) . '"> '
            . '<span class="description">' . esc_html__('Empty: every year.', 'taxmail') . '</span></td></tr></table>'
            . '<p><button type="submit" class="button button-secondary" form="' . esc_attr(Plugin::EXPORT_FORM) . '">' . esc_html__('Read the file', 'taxmail') . '</button></p>';
    }

    /** The mailbox fields; the page draws them under «Access», with its buttons beneath (D-981). */
    public static function account(): string
    {
        if (Settings::accountInConfig()) {
            return '<p>' . esc_html__('The mailbox is set in wp-config.php (TAXMAIL_IMAP_* constants).', 'taxmail') . '</p>';
        }

        $account = Settings::storedAccount();
        $passwordNote = match (true) {
            $account['sealed'] === '' => __('No password stored yet.', 'taxmail'),
            $account['opens']         => __('A password is stored encrypted. Leave empty to keep it.', 'taxmail'),
            default                   => __('The stored password can no longer be decrypted (the WordPress salts changed). Enter it again.', 'taxmail'),
        };

        $html = '<table class="form-table" role="presentation">';

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

    /** The steps from an empty mailbox to the first proposal, for the box beside the form (D-963). */
    public static function guide(string $inboxUrl): string
    {
        $steps = [
            esc_html__('Use one mailbox for purchases only, and let shops send their order mails there (or forward them).', 'taxmail'),
            esc_html__('Look up the IMAP server of your mail provider, with SSL on port 993 — for Strato: imap.strato.de.', 'taxmail'),
            esc_html__('Enter server, port, user (the full address) and password under «Access», then Save. The password is stored encrypted.', 'taxmail'),
            esc_html__('Click «Test connection» beneath them. It logs in and creates the folders «Importiert», «Verworfen» and «Nicht erkannt».', 'taxmail'),
            esc_html__('Choose how often to fetch under «Fetching», tick the shops under «Mail connections», and Save.', 'taxmail'),
            sprintf(
                /* translators: %s: link to the modeller's inbox */
                esc_html__('Open the %s and click «Fetch now». Older mails come with «Sort the backlog».', 'taxmail'),
                '<a href="' . esc_url($inboxUrl) . '">' . esc_html__('Inbox', 'taxmod') . '</a>'
            ),
        ];

        return '<ol><li>' . implode('</li><li>', $steps) . '</li></ol>';
    }

    /** A button-looking link to admin-post.php with its own nonce; it changes no setting, so unsaved fields stay unsaved. */
    /** @param array<string, string> $args */
    private static function actionLink(string $action, string $label, array $args = []): string
    {
        return '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => $action] + $args, admin_url('admin-post.php')), $action)) . '">'
            . esc_html($label) . '</a>';
    }
}
