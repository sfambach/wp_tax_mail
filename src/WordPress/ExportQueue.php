<?php declare(strict_types=1);

namespace TaxMail\WordPress;

use Taxmod\Core\Import\OrderProposal;

/**
 * The orders read from an uploaded shop export (Amazon's «Order History.csv»), handed to the inbox a slice per fetch so
 * thousands of old orders do not arrive in one run. Kept as JSON beside the sample mails' folder, closed to the web;
 * only what a proposal holds is stored — the export's addresses and payment columns never are.
 */
final class ExportQueue
{
    public const FOLDER = 'taxmail-import';

    /** Orders per fetch. */
    public const SLICE = 100;

    private const FILE = 'amazon-export.json';
    private const NEXT = 'taxmail_export_next';

    /** @param list<OrderProposal> $proposals */
    public static function store(array $proposals): bool
    {
        $directory = trailingslashit(WP_CONTENT_DIR) . self::FOLDER;

        if (! wp_mkdir_p($directory)) {
            return false;
        }

        file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($directory . '/index.php', "<?php declare(strict_types=1);\n// Silence.\n");
        $written = file_put_contents(self::path(), (string) wp_json_encode(array_map(static fn (OrderProposal $proposal): array => $proposal->toArray(), $proposals)));
        update_option(self::NEXT, 0, false);

        return $written !== false;
    }

    /**
     * The next slice; the position moves on at once, the inbox keeps each order once by its external id anyway.
     *
     * @return list<OrderProposal>
     */
    public static function take(int $count = self::SLICE): array
    {
        $all = self::all();
        $next = (int) get_option(self::NEXT, 0);

        if ($next >= count($all)) {
            return [];
        }

        update_option(self::NEXT, $next + $count, false);

        return array_map(static fn (array $row): OrderProposal => OrderProposal::fromArray($row), array_slice($all, $next, $count));
    }

    /** @return array{total: int, handed: int} */
    public static function progress(): array
    {
        $total = count(self::all());

        return ['total' => $total, 'handed' => min($total, (int) get_option(self::NEXT, 0))];
    }

    public static function rewind(): void
    {
        update_option(self::NEXT, 0, false);
    }

    public static function clear(): void
    {
        wp_delete_file(self::path());
        delete_option(self::NEXT);
    }

    /** @return list<array<string, mixed>> `mixed` because these are decoded JSON rows */
    private static function all(): array
    {
        $text = is_readable(self::path()) ? (string) file_get_contents(self::path()) : '';
        $rows = $text === '' ? [] : json_decode($text, true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private static function path(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . self::FOLDER . '/' . self::FILE;
    }
}
