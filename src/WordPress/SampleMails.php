<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use Taxmod\Core\Import\Mail\MailMessage;

/**
 * Sample mails of a shop without a parser, written as text files under wp-content so a parser can be written from
 * real mails without him having to save and send them. The folder answers no web request; the files hold only what a
 * parser reads (sender, subject, date, text), and «Delete samples» removes them.
 */
final class SampleMails
{
    public const FOLDER = 'taxmail-samples';

    /** Mails per sender — enough for a confirmation, a shipping and a delivery mail. */
    public const LIMIT = 6;

    public static function directory(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . self::FOLDER;
    }

    /**
     * @param list<MailMessage> $mails
     * @return int files written
     */
    public static function write(string $sender, array $mails): int
    {
        $directory = self::directory();

        if (! wp_mkdir_p($directory)) {
            return 0;
        }

        file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($directory . '/index.php', "<?php declare(strict_types=1);\n// Silence.\n");

        $prefix = sanitize_file_name(str_replace('@', '-at-', $sender));
        array_map('wp_delete_file', glob($directory . '/' . $prefix . '-*.txt') ?: []);
        $written = 0;

        foreach ($mails as $mail) {
            $text = 'From: ' . $mail->fromAddress . "\nSubject: " . $mail->subject . "\nDate: " . ($mail->date?->format(DATE_ATOM) ?? '')
                . "\n\n" . implode("\n", $mail->lines()) . "\n";
            $written += file_put_contents($directory . '/' . $prefix . '-' . $mail->uid . '.txt', $text) === false ? 0 : 1;
        }

        return $written;
    }

    public static function deleteAll(): void
    {
        array_map('wp_delete_file', glob(self::directory() . '/*.txt') ?: []);
    }

    public static function count(): int
    {
        return count(glob(self::directory() . '/*.txt') ?: []);
    }
}
