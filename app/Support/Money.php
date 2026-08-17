<?php

namespace App\Support;

use NumberFormatter;

/**
 * Money moves through the system as integer minor units and is only ever turned
 * into a string at the edges. Nothing here does arithmetic on floats.
 */
final class Money
{
    /**
     * Currencies whose smallest unit is the unit itself.
     *
     * @var array<int, string>
     */
    private const ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    public static function decimals(string $currency): int
    {
        return in_array(strtolower($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Minor units to a major-unit float, for display and for Stripe's dashboard.
     */
    public static function toMajor(int $minorUnits, string $currency): float
    {
        return $minorUnits / (10 ** self::decimals($currency));
    }

    public static function format(int $minorUnits, string $currency, ?string $locale = null): string
    {
        $formatter = new NumberFormatter(
            $locale ?? config('app.locale', 'en_US'),
            NumberFormatter::CURRENCY,
        );

        return $formatter->formatCurrency(
            self::toMajor($minorUnits, $currency),
            strtoupper($currency),
        ) ?: strtoupper($currency).' '.number_format(self::toMajor($minorUnits, $currency), self::decimals($currency));
    }
}
