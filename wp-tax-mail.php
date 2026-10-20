<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod Mail
 * Description:       Fetches order mails over IMAP and offers them as proposals for the Taxonomy Modeller; only picked items are written.
 * Version:           0.0.12
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Stefan Fambach
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxmail
 * GitHub Plugin URI: sfambach/wp_tax_mail
 * Primary Branch:    main
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/mail/konzept.md
 */

if (! defined('ABSPATH')) {
    exit;
}

$taxmailAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmailAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Taxmod Mail:</strong> '
            . esc_html__('run "composer install" in the plugin folder — the autoloader is missing.', 'taxmail')
            . '</p></div>';
    });

    return;
}

require_once $taxmailAutoloader;

TaxMail\WordPress\Plugin::boot(__FILE__);
