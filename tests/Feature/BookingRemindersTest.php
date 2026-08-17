<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00', 'UTC'));

    $this->staff = staffMember();
    $this->customer = Customer::factory()->create();

    // Reminders go out 24 hours ahead.
    $this->service = bookableService($this->staff, ['reminder_hours_before' => 24]);
});

function bookingStartingAt(string $at): Booking
{
    return Booking::factory()
        ->forSlot(test()->service, test()->staff, CarbonImmutable::parse($at, 'UTC'))
        ->for(test()->customer)
        ->create();
}

it('reminds a customer once the lead window opens', function () {
    // 23 hours away, so inside the 24-hour window.
    $booking = bookingStartingAt('2026-03-02 08:00');

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertSentTo($this->customer, BookingReminderNotification::class);

    expect($booking->fresh()->reminder_sent_at)->not->toBeNull();
});

it('leaves a booking that is still too far out', function () {
    // 47 hours away.
    $booking = bookingStartingAt('2026-03-03 08:00');

    $this->artisan('bookings:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();

    expect($booking->fresh()->reminder_sent_at)->toBeNull();
});

it('never sends the same reminder twice', function () {
    bookingStartingAt('2026-03-02 08:00');

    $this->artisan('bookings:send-reminders');
    $this->artisan('bookings:send-reminders');

    Notification::assertSentToTimes($this->customer, BookingReminderNotification::class, 1);
});

it('skips bookings that are not confirmed', function () {
    bookingStartingAt('2026-03-02 08:00')
        ->forceFill(['status' => BookingStatus::Pending])->save();

    $this->artisan('bookings:send-reminders');

    Notification::assertNothingSent();
});

it('skips cancelled bookings', function () {
    Booking::factory()
        ->forSlot($this->service, $this->staff, CarbonImmutable::parse('2026-03-02 08:00', 'UTC'))
        ->for($this->customer)
        ->cancelled()
        ->create();

    $this->artisan('bookings:send-reminders');

    Notification::assertNothingSent();
});

it('skips bookings that have already started', function () {
    bookingStartingAt('2026-02-28 08:00');

    $this->artisan('bookings:send-reminders');

    Notification::assertNothingSent();
});

it('honours a per-service lead time', function () {
    $shortNotice = bookableService($this->staff, ['reminder_hours_before' => 2]);

    // 23 hours away: due under the 24-hour service, not under the 2-hour one.
    Booking::factory()
        ->forSlot($shortNotice, $this->staff, CarbonImmutable::parse('2026-03-02 08:00', 'UTC'))
        ->for($this->customer)
        ->create();

    $this->artisan('bookings:send-reminders');

    Notification::assertNothingSent();

    // An hour before it starts, it is due.
    $this->travelTo(CarbonImmutable::parse('2026-03-02 07:00', 'UTC'));
    $this->artisan('bookings:send-reminders');

    Notification::assertSentTo($this->customer, BookingReminderNotification::class);
});

it('reports how many reminders it queued', function () {
    bookingStartingAt('2026-03-02 08:00');
    Booking::factory()
        ->forSlot($this->service, $this->staff, CarbonImmutable::parse('2026-03-02 07:00', 'UTC'))
        ->create();

    $this->artisan('bookings:send-reminders')
        ->expectsOutputToContain('Queued 2 reminder(s).')
        ->assertSuccessful();
});

it('releases holds that have lapsed', function () {
    $held = Booking::factory()
        ->forSlot($this->service, $this->staff, CarbonImmutable::parse('2026-03-05 10:00', 'UTC'))
        ->for($this->customer)
        ->awaitingDeposit(holdUntil: CarbonImmutable::parse('2026-03-01 08:00', 'UTC'))
        ->create();

    $this->artisan('bookings:release-holds')
        ->expectsOutputToContain('Released 1 expired hold(s).')
        ->assertSuccessful();

    expect($held->fresh())
        ->status->toBe(BookingStatus::Expired)
        ->cancelled_by->toBe('system');
});

it('leaves a hold that is still live', function () {
    $held = Booking::factory()
        ->forSlot($this->service, $this->staff, CarbonImmutable::parse('2026-03-05 10:00', 'UTC'))
        ->for($this->customer)
        ->awaitingDeposit(holdUntil: CarbonImmutable::parse('2026-03-01 09:30', 'UTC'))
        ->create();

    $this->artisan('bookings:release-holds')->assertSuccessful();

    expect($held->fresh()->status)->toBe(BookingStatus::Pending);
});
