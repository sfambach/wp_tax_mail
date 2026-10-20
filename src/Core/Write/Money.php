<?php declare(strict_types=1);

namespace TaxMail\Core\Write;

/**
 * Decimal strings as whole cents, so prices add up without float drift.
 */
final class Money
{
    public static function toCents(?string $amount): ?int
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $match) !== 1) {
            throw new WriteException('not a decimal amount: ' . $amount);
        }

        $cents = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return $match[1] === '-' ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
