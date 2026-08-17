<?php

use App\Support\TimeRange;
use App\Support\TimeRangeCollection;
use Carbon\CarbonImmutable;

function at(string $start, string $end): TimeRange
{
    return new TimeRange(
        CarbonImmutable::parse("2026-03-02 {$start}", 'UTC'),
        CarbonImmutable::parse("2026-03-02 {$end}", 'UTC'),
    );
}

/**
 * @return array<int, string>
 */
function readable(TimeRangeCollection $collection): array
{
    return array_map(
        fn (TimeRange $range) => $range->start->format('H:i').'-'.$range->end->format('H:i'),
        $collection->all(),
    );
}

it('sorts ranges by start', function () {
    $collection = TimeRangeCollection::make([at('14:00', '15:00'), at('09:00', '10:00')]);

    expect(readable($collection))->toBe(['09:00-10:00', '14:00-15:00']);
});

it('fuses overlapping ranges', function () {
    $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('11:00', '15:00')]);

    expect(readable($collection))->toBe(['09:00-15:00']);
});

it('fuses ranges that merely touch', function () {
    // Two back-to-back weekly rules must behave as one continuous shift, or a
    // 90-minute service could never be offered across the seam.
    $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('12:00', '17:00')]);

    expect(readable($collection))->toBe(['09:00-17:00']);
});

it('keeps genuinely separate ranges apart', function () {
    $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('13:00', '17:00')]);

    expect(readable($collection))->toBe(['09:00-12:00', '13:00-17:00']);
});

it('swallows a range fully inside another', function () {
    $collection = TimeRangeCollection::make([at('09:00', '17:00'), at('11:00', '12:00')]);

    expect(readable($collection))->toBe(['09:00-17:00']);
});

it('subtracts a single cut', function () {
    $collection = TimeRangeCollection::make([at('09:00', '17:00')])->subtract(at('12:00', '13:00'));

    expect(readable($collection))->toBe(['09:00-12:00', '13:00-17:00']);
});

it('subtracts several cuts across several ranges', function () {
    $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('13:00', '18:00')])
        ->subtract([at('10:00', '10:30'), at('14:00', '15:00'), at('17:30', '19:00')]);

    expect(readable($collection))->toBe([
        '09:00-10:00', '10:30-12:00', '13:00-14:00', '15:00-17:30',
    ]);
});

it('can be emptied entirely', function () {
    $collection = TimeRangeCollection::make([at('09:00', '17:00')])->subtract(at('08:00', '18:00'));

    expect($collection->isEmpty())->toBeTrue()
        ->and($collection->totalMinutes())->toBe(0);
});

it('clamps to a window', function () {
    $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('13:00', '18:00')])
        ->clampTo(at('11:00', '14:00'));

    expect(readable($collection))->toBe(['11:00-12:00', '13:00-14:00']);
});

describe('fullyContains', function () {
    it('accepts a range inside one contiguous block', function () {
        $collection = TimeRangeCollection::make([at('09:00', '17:00')]);

        expect($collection->fullyContains(at('10:00', '11:00')))->toBeTrue();
    });

    it('rejects a range that spans a gap between two blocks', function () {
        // 11:30-13:30 straddles the lunch break, so it is not bookable even
        // though both halves fall in working time.
        $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('13:00', '17:00')]);

        expect($collection->fullyContains(at('11:30', '13:30')))->toBeFalse();
    });
});

it('reports its total duration', function () {
    $collection = TimeRangeCollection::make([at('09:00', '12:00'), at('13:00', '17:00')]);

    expect($collection->totalMinutes())->toBe(420)
        ->and($collection)->toHaveCount(2);
});
