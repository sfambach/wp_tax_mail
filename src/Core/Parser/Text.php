<?php declare(strict_types=1);

namespace TaxMail\Core\Parser;

/**
 * The helpers every shop parser shares — ported one to one from the Python importer's mail.py.
 */
final class Text
{
    private const MONEY = '/(EUR|US \$|€|\$|GBP|£)\s*(-?[\d.]+,\d{1,5}|-?\d[\d,]*\.\d{1,5}|-?\d+)|(-?[\d.]+,\d{2})\s*(€|EUR)/u';
    private const CURRENCY = ['EUR' => 'EUR', '€' => 'EUR', 'US $' => 'USD', '$' => 'USD', 'GBP' => 'GBP', '£' => 'GBP'];

    /** «1.234,56» and «1,234.56» both become 1234.56. */
    public static function number(string $text): float
    {
        $text = trim($text);
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');

        if ($comma !== false && ($dot === false || $comma > $dot)) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        } else {
            $text = str_replace(',', '', $text);
        }

        return round((float) $text, 5);
    }

    /** @return array{0: ?float, 1: ?string} the first amount in the text and its currency */
    public static function money(?string $text): array
    {
        if ($text === null || preg_match(self::MONEY, $text, $match) !== 1) {
            return [null, null];
        }

        if (($match[1] ?? '') !== '') {
            return [self::number($match[2]), self::CURRENCY[$match[1]]];
        }

        return [self::number($match[3]), 'EUR'];
    }

    /**
     * The line $offset behind $label; a label ending in «*» matches by its start.
     *
     * @param list<string> $lines
     */
    public static function after(array $lines, string $label, int $offset = 1): ?string
    {
        $prefix = str_ends_with($label, '*') ? substr($label, 0, -1) : null;

        foreach ($lines as $index => $line) {
            if ($line === $label || ($prefix !== null && str_starts_with($line, $prefix))) {
                return $lines[$index + $offset] ?? null;
            }
        }

        return null;
    }

    /** A float as a decimal string with at most four places — the form OrderProposal carries. */
    public static function decimal(?float $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return rtrim(rtrim(sprintf('%.4F', round($amount, 4)), '0'), '.');
    }
}
