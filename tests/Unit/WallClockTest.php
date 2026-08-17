<?php

use App\Support\WallClock;

it('pads partial time strings', function (string $input, string $expected) {
    expect(WallClock::normaliseTime($input))->toBe($expected);
})->with([
    ['9:00', '09:00:00'],
    ['09:00', '09:00:00'],
    ['09:00:00', '09:00:00'],
    ['9:5:3', '09:05:03'],
]);

it('resolves a local wall clock time to the right instant', function () {
    // Lisbon is on UTC+0 in March, before the clocks change.
    expect(WallClock::toUtc('2026-03-23', '09:00:00', 'Europe/Lisbon')->format('Y-m-d H:i'))
        ->toBe('2026-03-23 09:00');

    // And UTC+1 the week after.
    expect(WallClock::toUtc('2026-03-30', '09:00:00', 'Europe/Lisbon')->format('Y-m-d H:i'))
        ->toBe('2026-03-30 08:00');
});

describe('daylight saving', function () {
    it('shifts a skipped local time forward rather than inventing one', function () {
        // New York jumps 02:00 -> 03:00 on 2026-03-08, so 02:30 never happens.
        $resolved = WallClock::local('2026-03-08', '02:30:00', 'America/New_York');

        expect($resolved->format('H:i'))->toBe('03:30')
            ->and(WallClock::isSkipped('2026-03-08', '02:30:00', 'America/New_York'))->toBeTrue();
    });

    it('leaves ordinary times alone', function () {
        expect(WallClock::isSkipped('2026-03-08', '09:00:00', 'America/New_York'))->toBeFalse();
    });

    it('picks the first occurrence of an ambiguous local time', function () {
        // Clocks go back on 2026-11-01, so 01:30 happens twice; the earlier one
        // is still on daylight time at -04:00.
        $resolved = WallClock::local('2026-11-01', '01:30:00', 'America/New_York');

        expect($resolved->format('H:i P'))->toBe('01:30 -04:00');
    });

    it('counts a short day as 23 hours', function () {
        $day = WallClock::wholeDay('2026-03-08', 'America/New_York');

        expect($day->durationInMinutes())->toBe(23 * 60);
    });

    it('counts a long day as 25 hours', function () {
        $day = WallClock::wholeDay('2026-11-01', 'America/New_York');

        expect($day->durationInMinutes())->toBe(25 * 60);
    });
});

it('spans a whole local day in UTC terms', function () {
    $day = WallClock::wholeDay('2026-06-15', 'Asia/Kolkata');

    // Kolkata is UTC+5:30, so its day starts the previous evening in UTC.
    expect($day->start->format('Y-m-d H:i'))->toBe('2026-06-14 18:30')
        ->and($day->end->format('Y-m-d H:i'))->toBe('2026-06-15 18:30');
});
