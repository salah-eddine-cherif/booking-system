<?php

use App\Support\TimeRange;
use Carbon\CarbonImmutable;

function utcRange(string $start, string $end): TimeRange
{
    return new TimeRange(
        CarbonImmutable::parse("2026-03-02 {$start}", 'UTC'),
        CarbonImmutable::parse("2026-03-02 {$end}", 'UTC'),
    );
}

it('rejects a range that does not move forwards', function (string $start, string $end) {
    utcRange($start, $end);
})->with([
    'zero length' => ['10:00', '10:00'],
    'backwards' => ['11:00', '10:00'],
])->throws(InvalidArgumentException::class);

it('normalises everything to UTC', function () {
    $range = new TimeRange(
        CarbonImmutable::parse('2026-03-02 09:00', 'Europe/Lisbon'),
        CarbonImmutable::parse('2026-03-02 17:00', 'Europe/Lisbon'),
    );

    expect($range->start->timezoneName)->toBe('UTC')
        ->and($range->start->format('H:i'))->toBe('09:00');
});

describe('overlaps', function () {
    it('treats ranges as half open, so touching is not overlapping', function () {
        expect(utcRange('10:00', '11:00')->overlaps(utcRange('11:00', '12:00')))->toBeFalse()
            ->and(utcRange('11:00', '12:00')->overlaps(utcRange('10:00', '11:00')))->toBeFalse();
    });

    it('detects partial and full overlap in both directions', function () {
        expect(utcRange('10:00', '12:00')->overlaps(utcRange('11:00', '13:00')))->toBeTrue()
            ->and(utcRange('11:00', '13:00')->overlaps(utcRange('10:00', '12:00')))->toBeTrue()
            ->and(utcRange('10:00', '14:00')->overlaps(utcRange('11:00', '12:00')))->toBeTrue()
            ->and(utcRange('11:00', '12:00')->overlaps(utcRange('10:00', '14:00')))->toBeTrue();
    });

    it('does not consider disjoint ranges overlapping', function () {
        expect(utcRange('09:00', '10:00')->overlaps(utcRange('11:00', '12:00')))->toBeFalse();
    });
});

describe('subtract', function () {
    it('returns the original when nothing is cut', function () {
        $pieces = utcRange('09:00', '17:00')->subtract(utcRange('18:00', '19:00'));

        expect($pieces)->toHaveCount(1)
            ->and((string) $pieces[0])->toBe((string) utcRange('09:00', '17:00'));
    });

    it('punches a hole through the middle', function () {
        $pieces = utcRange('09:00', '17:00')->subtract(utcRange('12:00', '13:00'));

        expect($pieces)->toHaveCount(2)
            ->and($pieces[0]->end->format('H:i'))->toBe('12:00')
            ->and($pieces[1]->start->format('H:i'))->toBe('13:00');
    });

    it('trims from the front', function () {
        $pieces = utcRange('09:00', '17:00')->subtract(utcRange('08:00', '10:00'));

        expect($pieces)->toHaveCount(1)
            ->and($pieces[0]->start->format('H:i'))->toBe('10:00')
            ->and($pieces[0]->end->format('H:i'))->toBe('17:00');
    });

    it('trims from the back', function () {
        $pieces = utcRange('09:00', '17:00')->subtract(utcRange('16:00', '18:00'));

        expect($pieces)->toHaveCount(1)
            ->and($pieces[0]->end->format('H:i'))->toBe('16:00');
    });

    it('returns nothing when fully covered', function () {
        expect(utcRange('10:00', '11:00')->subtract(utcRange('09:00', '17:00')))->toBe([]);
    });
});

it('intersects to the shared part, or nothing', function () {
    $shared = utcRange('09:00', '12:00')->intersect(utcRange('11:00', '15:00'));

    expect($shared->start->format('H:i'))->toBe('11:00')
        ->and($shared->end->format('H:i'))->toBe('12:00')
        ->and(utcRange('09:00', '10:00')->intersect(utcRange('11:00', '12:00')))->toBeNull();
});

it('knows when it fully encloses another range', function () {
    expect(utcRange('09:00', '17:00')->contains(utcRange('10:00', '11:00')))->toBeTrue()
        // Sharing an endpoint still counts as enclosing.
        ->and(utcRange('09:00', '17:00')->contains(utcRange('09:00', '17:00')))->toBeTrue()
        ->and(utcRange('09:00', '17:00')->contains(utcRange('16:00', '18:00')))->toBeFalse();
});

it('excludes its own end instant', function () {
    $range = utcRange('09:00', '17:00');

    expect($range->containsMoment(CarbonImmutable::parse('2026-03-02 09:00', 'UTC')))->toBeTrue()
        ->and($range->containsMoment(CarbonImmutable::parse('2026-03-02 17:00', 'UTC')))->toBeFalse();
});

it('expands on both sides', function () {
    $expanded = utcRange('10:00', '11:00')->expand(30);

    expect($expanded->start->format('H:i'))->toBe('09:30')
        ->and($expanded->end->format('H:i'))->toBe('11:30');
});

it('measures its own duration', function () {
    expect(utcRange('09:00', '17:00')->durationInMinutes())->toBe(480);
});
