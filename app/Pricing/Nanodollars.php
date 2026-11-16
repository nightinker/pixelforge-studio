<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * Exact money helpers. All money in this app is an integer number of
 * nanodollars (1 USD = 1_000_000_000), the precision Pixelforge reports in
 * GET /v1/usage. No floats, ever.
 */
final class Nanodollars
{
    public const PER_DOLLAR = 1_000_000_000;

    /**
     * Convert a Pixelforge rate ("12.50" USD per 1M tokens) into nanodollars
     * per single token. $12.50 / 1M tokens = 12_500 nanodollars per token, so
     * this is the rate multiplied by 1000.
     *
     * Rates with more than three decimals cannot be represented exactly, and
     * rounding them would silently mis-bill, so they are rejected.
     */
    public static function perTokenFromRate(string $ratePerMillion): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $ratePerMillion, $m)) {
            throw new InvalidArgumentException("Invalid rate: {$ratePerMillion}");
        }

        $fraction = $m[2] ?? '';

        if (strlen(rtrim($fraction, '0')) > 3) {
            throw new InvalidArgumentException("Rate has more than 3 decimals and cannot be represented exactly: {$ratePerMillion}");
        }

        return ((int) $m[1]) * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    /** Exactly nine decimals, e.g. "0.046125000", the format /v1/usage uses. */
    public static function toDecimal(int $nanodollars): string
    {
        if ($nanodollars < 0) {
            throw new InvalidArgumentException('Negative amounts are not supported.');
        }

        return intdiv($nanodollars, self::PER_DOLLAR)
            .'.'
            .str_pad((string) ($nanodollars % self::PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /** Parse "0.046125000" (up to nine decimals) into nanodollars. */
    public static function fromDecimal(string $decimal): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Invalid amount: {$decimal}");
        }

        return ((int) $m[1]) * self::PER_DOLLAR + (int) str_pad($m[2] ?? '', 9, '0');
    }

    /**
     * Human display that never rounds: trailing zeros are trimmed, but at
     * least two decimals are kept, e.g. "$0.046125" or "$1.50".
     */
    public static function format(int $nanodollars): string
    {
        $decimal = self::toDecimal($nanodollars);
        [$whole, $fraction] = explode('.', $decimal);
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');

        return '$'.$whole.'.'.$fraction;
    }
}
