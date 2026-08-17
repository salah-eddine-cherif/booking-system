<?php

namespace App\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A normalised set of time ranges: always sorted by start, never overlapping,
 * with touching ranges fused into one.
 *
 * Fusing matters. If a staff member has two back-to-back weekly rules of
 * 09:00-12:00 and 12:00-17:00, a 90-minute service must still be offerable at
 * 11:00 — which only works if the engine sees one 09:00-17:00 block.
 *
 * @implements IteratorAggregate<int, TimeRange>
 */
final class TimeRangeCollection implements Countable, IteratorAggregate
{
    /** @var array<int, TimeRange> */
    private array $ranges;

    /**
     * @param  array<int, TimeRange>  $ranges
     */
    public function __construct(array $ranges = [])
    {
        $this->ranges = self::normalise($ranges);
    }

    /**
     * @param  array<int, TimeRange>  $ranges
     */
    public static function make(array $ranges = []): self
    {
        return new self($ranges);
    }

    public static function empty(): self
    {
        return new self;
    }

    public function add(TimeRange $range): self
    {
        return new self([...$this->ranges, $range]);
    }

    /**
     * @param  TimeRange|self|array<int, TimeRange>  $other
     */
    public function merge(TimeRange|self|array $other): self
    {
        return new self([...$this->ranges, ...self::toArray($other)]);
    }

    /**
     * Cut every given range out of this collection.
     *
     * @param  TimeRange|self|array<int, TimeRange>  $other
     */
    public function subtract(TimeRange|self|array $other): self
    {
        $remaining = $this->ranges;

        foreach (self::toArray($other) as $cut) {
            $next = [];

            foreach ($remaining as $range) {
                foreach ($range->subtract($cut) as $piece) {
                    $next[] = $piece;
                }
            }

            $remaining = $next;

            if ($remaining === []) {
                break;
            }
        }

        return new self($remaining);
    }

    /**
     * Keep only the parts of this collection that fall inside $window.
     */
    public function clampTo(TimeRange $window): self
    {
        $clamped = [];

        foreach ($this->ranges as $range) {
            if ($piece = $range->intersect($window)) {
                $clamped[] = $piece;
            }
        }

        return new self($clamped);
    }

    /**
     * Is $range wholly inside a single contiguous block of this collection?
     */
    public function fullyContains(TimeRange $range): bool
    {
        foreach ($this->ranges as $candidate) {
            if ($candidate->contains($range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, TimeRange>
     */
    public function all(): array
    {
        return $this->ranges;
    }

    public function first(): ?TimeRange
    {
        return $this->ranges[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function totalMinutes(): int
    {
        return array_sum(array_map(
            fn (TimeRange $range) => $range->durationInMinutes(),
            $this->ranges,
        ));
    }

    public function count(): int
    {
        return count($this->ranges);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->ranges);
    }

    /**
     * Sort by start, then fuse anything that overlaps or touches.
     *
     * @param  array<int, TimeRange>  $ranges
     * @return array<int, TimeRange>
     */
    private static function normalise(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }

        usort($ranges, fn (TimeRange $a, TimeRange $b) => $a->start <=> $b->start);

        $merged = [array_shift($ranges)];

        foreach ($ranges as $range) {
            $last = $merged[count($merged) - 1];

            if ($range->start <= $last->end) {
                // Overlapping or touching: extend the open block instead of
                // starting a new one.
                $merged[count($merged) - 1] = new TimeRange(
                    $last->start,
                    $last->end->max($range->end),
                );

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * @param  TimeRange|self|array<int, TimeRange>  $value
     * @return array<int, TimeRange>
     */
    private static function toArray(TimeRange|self|array $value): array
    {
        return match (true) {
            $value instanceof TimeRange => [$value],
            $value instanceof self => $value->all(),
            default => $value,
        };
    }
}
