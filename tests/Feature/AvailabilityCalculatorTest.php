<?php

use App\Models\AvailabilityException;
use App\Models\Booking;
use App\Services\Availability\AvailabilityCalculator;
use Carbon\CarbonImmutable;

// 2026-03-02 is a Monday, and sits well clear of any clock change.
const MONDAY = '2026-03-02';

const SUNDAY = '2026-03-01';

beforeEach(function () {
    $this->calculator = app(AvailabilityCalculator::class);

    // Midnight the Friday before, so nothing is filtered out by lead times.
    $this->travelTo(CarbonImmutable::parse('2026-02-27 00:00', 'UTC'));
});

describe('slot grid', function () {
    it('offers slots on the increment, ending no later than the shift does', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        $slots = $this->calculator->slotsFor($service, $staff, dayWindow(MONDAY));

        // 09:00 through 16:00; a 16:30 start would run past the 17:00 finish.
        expect(slotTimes($slots))->toBe([
            '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30',
            '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00',
        ]);
    });

    it('anchors the grid on the start of the shift, not the top of the hour', function () {
        $staff = staffMember(start: '09:20:00', end: '12:00:00');
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        $slots = $this->calculator->slotsFor($service, $staff, dayWindow(MONDAY));

        expect(slotTimes($slots))->toBe(['09:20', '09:50', '10:20', '10:50']);
    });

    it('offers nothing on a day with no rule', function () {
        $staff = staffMember();
        $service = bookableService($staff);

        expect($this->calculator->slotsFor($service, $staff, dayWindow(SUNDAY)))->toBeEmpty();
    });

    it('will not stretch an appointment across a gap in the day', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        AvailabilityException::factory()->blocking(MONDAY, '12:00:00', '13:00:00')->for($staff)->create();

        $slots = slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY)));

        // 11:30 would run into the break, and the grid restarts after it.
        expect($slots)->not->toContain('11:30', '12:00', '12:30')
            ->and($slots)->toContain('11:00', '13:00');
    });

    it('treats two touching rules as one continuous shift', function () {
        $staff = staffMember(start: '09:00:00', end: '12:00:00');
        $staff->availabilityRules()->create([
            'day_of_week' => 1, 'start_time' => '12:00:00', 'end_time' => '17:00:00',
        ]);

        $service = bookableService($staff, ['duration_minutes' => 90, 'slot_increment_minutes' => 60]);

        // An 11:00 start runs to 12:30, straight through the seam between rules.
        expect(slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY))))
            ->toContain('11:00');
    });
});

describe('existing bookings', function () {
    it('removes every slot that would overlap one', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        Booking::factory()
            ->forSlot($service, $staff, CarbonImmutable::parse(MONDAY.' 10:00', 'UTC'))
            ->create();

        $slots = slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY)));

        expect($slots)->not->toContain('09:30', '10:00', '10:30')
            // 09:00-10:00 ends exactly as the booking starts, so it survives.
            ->and($slots)->toContain('09:00', '11:00');
    });

    it('keeps buffers clear on both sides', function () {
        $staff = staffMember();
        $service = bookableService($staff, [
            'duration_minutes' => 60,
            'slot_increment_minutes' => 30,
            'buffer_after_minutes' => 15,
        ]);

        // Blocks 11:00-12:15 once the after-buffer is counted.
        Booking::factory()
            ->forSlot($service, $staff, CarbonImmutable::parse(MONDAY.' 11:00', 'UTC'))
            ->create();

        $slots = slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY)));

        expect($slots)->toContain('09:30', '12:30')
            // A 10:00 start ends 11:00 but blocks to 11:15, colliding with the booking.
            ->and($slots)->not->toContain('10:00', '11:00', '11:30', '12:00');
    });

    it('ignores cancelled and expired bookings', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        Booking::factory()
            ->forSlot($service, $staff, CarbonImmutable::parse(MONDAY.' 10:00', 'UTC'))
            ->cancelled()
            ->create();

        expect(slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY))))
            ->toContain('10:00');
    });

    it('does not let one staff member block another', function () {
        $ana = staffMember();
        $marcus = staffMember();
        $service = bookableService($ana, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);
        $service->staff()->attach($marcus);

        Booking::factory()
            ->forSlot($service, $ana, CarbonImmutable::parse(MONDAY.' 10:00', 'UTC'))
            ->create();

        expect(slotTimes($this->calculator->slotsFor($service, $marcus, dayWindow(MONDAY))))
            ->toContain('10:00');
    });
});

describe('group capacity', function () {
    it('keeps offering a session until its places run out', function () {
        $staff = staffMember();
        $service = bookableService($staff, [
            'duration_minutes' => 45,
            'slot_increment_minutes' => 60,
            'capacity' => 3,
        ]);

        $startsAt = CarbonImmutable::parse(MONDAY.' 10:00', 'UTC');

        Booking::factory()->count(2)->forSlot($service, $staff, $startsAt)->create();

        $slot = $this->calculator->slotsFor($service, $staff, dayWindow(MONDAY))
            ->firstWhere(fn ($slot) => $slot->startsAt->equalTo($startsAt));

        expect($slot->booked)->toBe(2)
            ->and($slot->remainingCapacity())->toBe(1);
    });

    it('withdraws a full session', function () {
        $staff = staffMember();
        $service = bookableService($staff, [
            'duration_minutes' => 45,
            'slot_increment_minutes' => 60,
            'capacity' => 2,
        ]);

        Booking::factory()->count(2)
            ->forSlot($service, $staff, CarbonImmutable::parse(MONDAY.' 10:00', 'UTC'))
            ->create();

        expect(slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY))))
            ->not->toContain('10:00');
    });

    it('never shares a slot with a different service', function () {
        $staff = staffMember();
        $class = bookableService($staff, [
            'duration_minutes' => 45, 'slot_increment_minutes' => 60, 'capacity' => 8,
        ]);
        $oneToOne = bookableService($staff, ['duration_minutes' => 60]);

        Booking::factory()
            ->forSlot($oneToOne, $staff, CarbonImmutable::parse(MONDAY.' 10:00', 'UTC'))
            ->create();

        expect(slotTimes($this->calculator->slotsFor($class, $staff, dayWindow(MONDAY))))
            ->not->toContain('10:00');
    });
});

describe('date overrides', function () {
    it('clears a whole day off', function () {
        $staff = staffMember();
        $service = bookableService($staff);

        AvailabilityException::factory()->dayOff(MONDAY)->for($staff)->create();

        expect($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY)))->toBeEmpty();
    });

    it('lets a one-off shift replace the weekly rules for that date', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        AvailabilityException::factory()->extraHours(MONDAY, '14:00:00', '16:00:00')->for($staff)->create();

        expect(slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY))))
            ->toBe(['14:00', '14:30', '15:00']);
    });

    it('opens a day that has no weekly rule at all', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 30]);

        AvailabilityException::factory()->extraHours(SUNDAY, '10:00:00', '12:00:00')->for($staff)->create();

        expect(slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(SUNDAY))))
            ->toBe(['10:00', '10:30', '11:00']);
    });

    it('respects a rule that has not taken effect yet', function () {
        $staff = staffMember();
        $service = bookableService($staff);

        $staff->availabilityRules()->update(['effective_from' => '2026-06-01']);

        expect($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY)))->toBeEmpty();
    });
});

describe('timezones', function () {
    it('keeps the shift at the same wall clock time across a clock change', function () {
        // Lisbon runs on UTC+0 in winter and UTC+1 in summer. A 09:00 start has
        // to stay 09:00 for the staff member on both sides of the change.
        $staff = staffMember(timezone: 'Europe/Lisbon');
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 60]);

        $beforeClocksChange = $this->calculator->slotsFor($service, $staff, dayWindow('2026-03-23', timezone: 'Europe/Lisbon'));
        $afterClocksChange = $this->calculator->slotsFor($service, $staff, dayWindow('2026-03-30', timezone: 'Europe/Lisbon'));

        expect(slotTimes($beforeClocksChange, 'Europe/Lisbon')[0])->toBe('09:00')
            ->and(slotTimes($afterClocksChange, 'Europe/Lisbon')[0])->toBe('09:00')
            // The same wall-clock hour, an hour apart in absolute terms.
            ->and(slotInstants($beforeClocksChange)[0])->toBe('2026-03-23 09:00')
            ->and(slotInstants($afterClocksChange)[0])->toBe('2026-03-30 08:00');
    });

    it('answers a calendar day in the zone the caller asked in', function () {
        // Kolkata is UTC+5:30, so a New York Monday only overlaps part of the
        // staff member's Monday and Tuesday.
        $staff = staffMember(timezone: 'Asia/Kolkata');
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 60]);

        $slots = $this->calculator->slotsFor(
            $service,
            $staff,
            dayWindow('2026-06-15', timezone: 'America/New_York'),
        );

        expect($slots)->not->toBeEmpty();

        foreach ($slots as $slot) {
            expect($slot->startsAt->setTimezone('America/New_York')->format('Y-m-d'))
                ->toBe('2026-06-15');
        }
    });

    it('shortens an overnight shift that crosses a spring-forward', function () {
        // 22:00-06:00 on the night the clocks go forward is seven real hours.
        $staff = staffMember(timezone: 'America/New_York', start: '22:00:00', end: '06:00:00', days: [6]);

        $working = $this->calculator->workingHours(
            $staff,
            dayWindow('2026-03-07', '2026-03-08', 'America/New_York'),
        );

        expect($working->totalMinutes())->toBe(7 * 60);
    });

    it('offers slots either side of midnight on an overnight shift', function () {
        $staff = staffMember(start: '22:00:00', end: '02:00:00', days: [6]);
        $service = bookableService($staff, ['duration_minutes' => 60, 'slot_increment_minutes' => 60]);

        $slots = $this->calculator->slotsFor($service, $staff, dayWindow('2026-03-07', '2026-03-08'));

        expect(slotInstants($slots))->toBe([
            '2026-03-07 22:00', '2026-03-07 23:00', '2026-03-08 00:00', '2026-03-08 01:00',
        ]);
    });
});

describe('booking horizon', function () {
    it('hides slots inside the minimum notice period', function () {
        $staff = staffMember();
        $service = bookableService($staff, [
            'duration_minutes' => 60,
            'slot_increment_minutes' => 30,
            'min_notice_minutes' => 120,
        ]);

        $this->travelTo(CarbonImmutable::parse(MONDAY.' 09:00', 'UTC'));

        expect(slotTimes($this->calculator->slotsFor($service, $staff, dayWindow(MONDAY)))[0])
            ->toBe('11:00');
    });

    it('hides slots beyond the booking horizon', function () {
        $staff = staffMember();
        $service = bookableService($staff, ['max_advance_days' => 2]);

        expect($this->calculator->slotsFor($service, $staff, dayWindow('2026-03-16')))->toBeEmpty();
    });
});

it('gathers slots from every staff member offering the service', function () {
    $ana = staffMember(start: '09:00:00', end: '11:00:00');
    $marcus = staffMember(start: '09:00:00', end: '11:00:00');

    $service = bookableService($ana, ['duration_minutes' => 60, 'slot_increment_minutes' => 60]);
    $service->staff()->attach($marcus);

    $slots = $this->calculator->slotsForService($service, dayWindow(MONDAY));

    // 09:00 and 10:00 from each of the two staff members.
    expect($slots)->toHaveCount(4)
        ->and($slots->pluck('staffId')->unique()->values()->all())
        ->toEqualCanonicalizing([$ana->id, $marcus->id]);
});

it('ignores staff who are not bookable', function () {
    $staff = staffMember();
    $staff->update(['is_bookable' => false]);

    $service = bookableService($staff);

    expect($this->calculator->slotsForService($service, dayWindow(MONDAY)))->toBeEmpty();
});
