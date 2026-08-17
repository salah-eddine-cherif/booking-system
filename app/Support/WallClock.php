<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Converting "09:00 on this date, in this zone" into a real instant.
 *
 * Two days a year that conversion is not a function:
 *
 *  - Spring forward: 02:30 simply does not exist. PHP resolves it to 03:30, and
 *    we keep that: the shift still starts at the earliest moment it can.
 *  - Fall back: 01:30 happens twice. PHP picks the first (still-DST) occurrence,
 *    which is the one a staff member means when they say "I start at 01:30".
 *
 * Both behaviours are relied upon deliberately; see tests/Unit/WallClockTest.php.
 */
final class WallClock
{
    /**
     * Resolve a local wall-clock time on a local date to a UTC instant.
     */
    public static function toUtc(string $date, string $time, string $timezone): CarbonImmutable
    {
        return self::local($date, $time, $timezone)->utc();
    }

    /**
     * The same resolution, kept in the local zone.
     */
    public static function local(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date.' '.self::normaliseTime($time),
            $timezone,
        );
    }

    /**
     * Midnight-to-midnight for a local calendar date.
     *
     * Anchored on the next calendar date's midnight rather than "+24 hours", so
     * a 23- or 25-hour DST day still covers exactly one calendar day.
     */
    public static function wholeDay(string $date, string $timezone): TimeRange
    {
        $nextDate = CarbonImmutable::parse($date)->addDay()->format('Y-m-d');

        return new TimeRange(
            self::local($date, '00:00:00', $timezone),
            self::local($nextDate, '00:00:00', $timezone),
        );
    }

    /**
     * Was this local time skipped by a DST jump on that date?
     */
    public static function isSkipped(string $date, string $time, string $timezone): bool
    {
        $resolved = self::local($date, $time, $timezone);

        return $resolved->format('H:i') !== substr(self::normaliseTime($time), 0, 5);
    }

    /**
     * Accepts "9:00", "09:00" or "09:00:00" and returns "09:00:00".
     */
    public static function normaliseTime(string $time): string
    {
        $parts = array_map(
            fn (string $part) => str_pad($part, 2, '0', STR_PAD_LEFT),
            explode(':', trim($time)),
        );

        return implode(':', array_pad(array_slice($parts, 0, 3), 3, '00'));
    }
}
