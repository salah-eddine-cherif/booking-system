<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A half-open instant range: [start, end).
 *
 * Half-open is the whole point. A 10:00-11:00 appointment and an 11:00-12:00
 * appointment share an endpoint but do not overlap, and every comparison in the
 * availability engine depends on that being unambiguous.
 */
final readonly class TimeRange
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->start = CarbonImmutable::instance($start)->utc();
        $this->end = CarbonImmutable::instance($end)->utc();

        if ($this->end <= $this->start) {
            throw new InvalidArgumentException(
                "A time range must end after it starts, got [{$this->start->toIso8601String()}, {$this->end->toIso8601String()})."
            );
        }
    }

    public static function make(DateTimeInterface $start, DateTimeInterface $end): self
    {
        return new self($start, $end);
    }

    public static function fromDuration(DateTimeInterface $start, int $minutes): self
    {
        return new self($start, CarbonImmutable::instance($start)->addMinutes($minutes));
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /** Does this range fully enclose $other? */
    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $this->end >= $other->end;
    }

    public function containsMoment(DateTimeInterface $moment): bool
    {
        $moment = CarbonImmutable::instance($moment)->utc();

        return $moment >= $this->start && $moment < $this->end;
    }

    /** Ranges that merely touch ([9,10) and [10,11)) are adjacent, not overlapping. */
    public function isAdjacentTo(self $other): bool
    {
        return $this->end->equalTo($other->start) || $other->end->equalTo($this->start);
    }

    public function intersect(self $other): ?self
    {
        if (! $this->overlaps($other)) {
            return null;
        }

        return new self(
            $this->start->max($other->start),
            $this->end->min($other->end),
        );
    }

    /**
     * Remove $other from this range.
     *
     * Returns 0 pieces (fully covered), 1 piece (trimmed at one end), or 2
     * pieces (a hole punched through the middle).
     *
     * @return array<int, self>
     */
    public function subtract(self $other): array
    {
        if (! $this->overlaps($other)) {
            return [$this];
        }

        $pieces = [];

        if ($other->start > $this->start) {
            $pieces[] = new self($this->start, $other->start);
        }

        if ($other->end < $this->end) {
            $pieces[] = new self($other->end, $this->end);
        }

        return $pieces;
    }

    /**
     * Widen the range by $minutes on both sides.
     */
    public function expand(int $minutes): self
    {
        return new self(
            $this->start->subMinutes($minutes),
            $this->end->addMinutes($minutes),
        );
    }

    public function durationInMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function withTimezone(string $timezone): array
    {
        return [
            'start' => $this->start->setTimezone($timezone)->toIso8601String(),
            'end' => $this->end->setTimezone($timezone)->toIso8601String(),
        ];
    }

    public function __toString(): string
    {
        return "[{$this->start->toIso8601String()}, {$this->end->toIso8601String()})";
    }
}
