<?php

use App\Models\Service;
use App\Models\User;
use App\Services\Availability\TimeSlot;
use App\Services\Payments\DepositService;
use App\Support\TimeRange;
use App\Support\WallClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\FakeDepositService;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests cover the pure value objects and need no database.
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A staff member with a weekly pattern expressed in their own timezone.
 *
 * @param  array<int, int>  $days  0 = Sunday .. 6 = Saturday
 */
function staffMember(
    string $timezone = 'UTC',
    string $start = '09:00:00',
    string $end = '17:00:00',
    array $days = [1, 2, 3, 4, 5],
): User {
    $staff = User::factory()->inTimezone($timezone)->create();

    foreach ($days as $day) {
        $staff->availabilityRules()->create([
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    return $staff;
}

/**
 * A service the given staff member is qualified to deliver, with the booking
 * horizon opened up so tests can travel freely.
 *
 * @param  array<string, mixed>  $attributes
 */
function bookableService(User $staff, array $attributes = []): Service
{
    $service = Service::factory()->bookableAnytime()->create($attributes);

    $service->staff()->attach($staff);

    return $service;
}

/**
 * A UTC window covering whole local dates, the way the API asks for them.
 */
function dayWindow(string $from, ?string $to = null, string $timezone = 'UTC'): TimeRange
{
    return new TimeRange(
        WallClock::wholeDay($from, $timezone)->start,
        WallClock::wholeDay($to ?? $from, $timezone)->end,
    );
}

/**
 * Slot start times as "HH:MM" in the given zone — far easier to assert against
 * than a list of ISO 8601 strings.
 *
 * @param  Collection<int, TimeSlot>  $slots
 * @return array<int, string>
 */
function slotTimes(Collection $slots, string $timezone = 'UTC'): array
{
    return $slots
        ->map(fn (TimeSlot $slot) => $slot->startsAt->setTimezone($timezone)->format('H:i'))
        ->all();
}

/**
 * @param  Collection<int, TimeSlot>  $slots
 * @return array<int, string>
 */
function slotInstants(Collection $slots): array
{
    return $slots
        ->map(fn (TimeSlot $slot) => $slot->startsAt->format('Y-m-d H:i'))
        ->all();
}

/**
 * Swap Stripe out for a recorder. Returns the fake so tests can assert on what
 * would have been charged.
 */
function fakeDeposits(): FakeDepositService
{
    $fake = new FakeDepositService;

    app()->instance(DepositService::class, $fake);

    return $fake;
}
