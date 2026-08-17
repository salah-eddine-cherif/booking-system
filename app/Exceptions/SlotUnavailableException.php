<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Raised when a requested slot cannot be booked. Carries a machine-readable
 * reason so the API can return something more useful than "unavailable".
 */
class SlotUnavailableException extends RuntimeException
{
    public const OUTSIDE_WORKING_HOURS = 'outside_working_hours';

    public const ALREADY_BOOKED = 'already_booked';

    public const AT_CAPACITY = 'at_capacity';

    public const TOO_SOON = 'too_soon';

    public const TOO_FAR_AHEAD = 'too_far_ahead';

    public const NOT_ON_SLOT_GRID = 'not_on_slot_grid';

    public const STAFF_CANNOT_PERFORM = 'staff_cannot_perform';

    public const SERVICE_INACTIVE = 'service_inactive';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function outsideWorkingHours(CarbonImmutable $startsAt): self
    {
        return new self(
            self::OUTSIDE_WORKING_HOURS,
            "The staff member is not available at {$startsAt->toIso8601String()}.",
        );
    }

    public static function alreadyBooked(CarbonImmutable $startsAt): self
    {
        return new self(
            self::ALREADY_BOOKED,
            "That slot was taken while you were booking it ({$startsAt->toIso8601String()}).",
        );
    }

    public static function atCapacity(CarbonImmutable $startsAt, int $capacity): self
    {
        return new self(
            self::AT_CAPACITY,
            "That session is full ({$capacity} of {$capacity} places taken at {$startsAt->toIso8601String()}).",
        );
    }

    public static function tooSoon(int $minNoticeMinutes): self
    {
        return new self(
            self::TOO_SOON,
            "This service needs at least {$minNoticeMinutes} minutes of notice.",
        );
    }

    public static function tooFarAhead(int $maxAdvanceDays): self
    {
        return new self(
            self::TOO_FAR_AHEAD,
            "This service can only be booked up to {$maxAdvanceDays} days in advance.",
        );
    }

    public static function notOnSlotGrid(CarbonImmutable $startsAt, int $increment): self
    {
        return new self(
            self::NOT_ON_SLOT_GRID,
            "{$startsAt->toIso8601String()} is not one of the offered start times (slots run every {$increment} minutes).",
        );
    }

    public static function staffCannotPerform(): self
    {
        return new self(
            self::STAFF_CANNOT_PERFORM,
            'That staff member does not offer this service.',
        );
    }

    public static function serviceInactive(): self
    {
        return new self(
            self::SERVICE_INACTIVE,
            'This service is not currently bookable.',
        );
    }
}
