<?php declare(strict_types=1);

namespace TaxMail\WordPress;

/**
 * The module's one table: the inbox of proposals (D-942, M5). Not model data — a proposal becomes data only when
 * the owner takes it. Guarded by a schema version so upgrades are deterministic (CD-6).
 */
final class Schema
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'taxmail_schema_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taxmail_proposals';
    }

    public static function upgrade(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) === self::VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // utf8mb4 index prefixes stay under MySQL 5.7's 767-byte limit (fambach.net runs 5.7).
        dbDelta(sprintf(
            "CREATE TABLE %s (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id varchar(191) NOT NULL DEFAULT '',
  mail_uid bigint(20) unsigned NOT NULL,
  uid_validity bigint(20) unsigned NOT NULL,
  sender varchar(191) NOT NULL DEFAULT '',
  subject text NOT NULL,
  received_at datetime NULL,
  parser varchar(64) NULL,
  platform varchar(64) NULL,
  order_number varchar(64) NULL,
  kind varchar(20) NULL,
  status varchar(20) NOT NULL,
  proposal longtext NULL,
  error text NULL,
  written longtext NULL,
  created_at datetime NOT NULL,
  handled_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY message_id (message_id)
) %s;",
            self::table(),
            $wpdb->get_charset_collate()
        ));

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
}
