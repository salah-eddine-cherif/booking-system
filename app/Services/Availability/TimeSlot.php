<?php

namespace App\Services\Availability;

use App\Support\TimeRange;
use Carbon\CarbonImmutable;

/**
 * A bookable opening. Instants are UTC; render them in the customer's zone at
 * the edge of the application, never before.
 */
final readonly class TimeSlot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $staffId,
        public int $capacity = 1,
        public int $booked = 0,
    ) {}

    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->booked);
    }

    public function range(): TimeRange
    {
        return new TimeRange($this->startsAt, $this->endsAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $timezone = 'UTC'): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'starts_at_local' => $this->startsAt->setTimezone($timezone)->toIso8601String(),
            'ends_at_local' => $this->endsAt->setTimezone($timezone)->toIso8601String(),
            'timezone' => $timezone,
            'staff_id' => $this->staffId,
            'capacity' => $this->capacity,
            'booked' => $this->booked,
            'remaining' => $this->remainingCapacity(),
        ];
    }
}
