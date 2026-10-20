<?php declare(strict_types=1);

namespace TaxMail\Core\Write;

/**
 * Decimal strings as whole ten-thousandths, so prices add up without float drift. Four places, as the Python
 * importer wrote them (a unit price can be a line total divided by the quantity).
 */
final class Money
{
    public const SCALE = 10000;

    public static function toUnits(?string $amount): ?int
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,5}))?$/', trim($amount), $match) !== 1) {
            throw new WriteException('not a decimal amount: ' . $amount);
        }

        $fraction = (int) round((float) ('0.' . ($match[3] ?? '0')) * self::SCALE);
        $units = (int) $match[2] * self::SCALE + $fraction;

        return $match[1] === '-' ? -$units : $units;
    }

    /** «12.5», «0.1234», «3» — trailing zeros dropped, as the model shows them anyway. */
    public static function format(int $units): string
    {
        $text = sprintf('%s%d.%04d', $units < 0 ? '-' : '', intdiv(abs($units), self::SCALE), abs($units) % self::SCALE);

        return rtrim(rtrim($text, '0'), '.');
    }
}
