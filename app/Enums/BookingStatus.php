<?php

namespace App\Enums;

enum BookingStatus: string
{
    /** Slot is held, but a required deposit has not been paid yet. */
    case Pending = 'pending';

    /** Slot is locked in. */
    case Confirmed = 'confirmed';

    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /** Slot is released because the payment hold expired. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::NoShow => 'No show',
            self::Expired => 'Expired',
        };
    }

    /**
     * Statuses that still occupy the calendar. Anything not in this list is
     * invisible to the availability calculator and to double-booking checks.
     *
     * @return array<int, self>
     */
    public static function blocking(): array
    {
        return [self::Pending, self::Confirmed, self::Completed, self::NoShow];
    }

    /**
     * @return array<int, string>
     */
    public static function blockingValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::blocking());
    }

    public function occupiesSlot(): bool
    {
        return in_array($this, self::blocking(), true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }
}
