<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\DepositRequested;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingCancelledNotification;
use App\Notifications\BookingConfirmedNotification;
use App\Services\Booking\BookingService;
use App\Services\Booking\PendingBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-27 00:00', 'UTC'));

    $this->deposits = fakeDeposits();
    $this->bookings = app(BookingService::class);

    $this->staff = staffMember();
    $this->service = bookableService($this->staff, [
        'duration_minutes' => 60,
        'slot_increment_minutes' => 30,
    ]);
    $this->customer = Customer::factory()->create();
});

/** 10:00 UTC on Monday 2026-03-02. */
function slotAt(string $time = '10:00'): CarbonImmutable
{
    return CarbonImmutable::parse("2026-03-02 {$time}", 'UTC');
}

function attempt(
    BookingService $bookings,
    Service $service,
    User $staff,
    Customer $customer,
    string $time = '10:00',
    string $timezone = 'UTC',
): Booking {
    return $bookings->book(new PendingBooking(
        service: $service,
        staff: $staff,
        customer: $customer,
        startsAt: slotAt($time),
        customerTimezone: $timezone,
    ));
}

describe('creating a booking', function () {
    it('confirms immediately when no deposit is due', function () {
        Notification::fake();

        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->payment_status)->toBe(PaymentStatus::NotRequired)
            ->and($booking->confirmed_at)->not->toBeNull()
            ->and($booking->hold_expires_at)->toBeNull()
            ->and($booking->reference)->toStartWith('BK-')
            ->and($booking->ends_at->format('H:i'))->toBe('11:00');

        Notification::assertSentTo($this->customer, BookingConfirmedNotification::class);
    });

    it('records the blocked range including buffers', function () {
        $this->service->update(['buffer_before_minutes' => 10, 'buffer_after_minutes' => 20]);

        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        expect($booking->blocked_starts_at->format('H:i'))->toBe('09:50')
            ->and($booking->blocked_ends_at->format('H:i'))->toBe('11:20');
    });

    it('holds the slot and asks for payment when a deposit is due', function () {
        Event::fake([DepositRequested::class, BookingConfirmed::class]);

        $this->service->update(['deposit_type' => 'percentage', 'deposit_value' => 25]);

        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        expect($booking->status)->toBe(BookingStatus::Pending)
            ->and($booking->payment_status)->toBe(PaymentStatus::Pending)
            ->and($booking->deposit_amount)->toBe(2250)
            ->and($booking->hold_expires_at->format('H:i'))->toBe('00:15')
            ->and($this->deposits->requested)->toBe([$booking->reference]);

        Event::assertDispatched(DepositRequested::class);
        Event::assertNotDispatched(BookingConfirmed::class);
    });

    it('bases the deposit on the staff member’s own price', function () {
        $this->service->update(['deposit_type' => 'percentage', 'deposit_value' => 50]);
        $this->service->staff()->updateExistingPivot($this->staff->id, ['price_amount' => 20000]);

        $booking = attempt($this->bookings, $this->service, $this->staff->fresh(), $this->customer);

        expect($booking->price_amount)->toBe(20000)
            ->and($booking->deposit_amount)->toBe(10000);
    });

    it('stores the customer’s timezone so times read back correctly', function () {
        $booking = attempt(
            $this->bookings, $this->service, $this->staff, $this->customer,
            time: '10:00', timezone: 'America/New_York',
        );

        expect($booking->customer_timezone)->toBe('America/New_York')
            // 10:00 UTC is 05:00 in New York.
            ->and($booking->localStartsAt()->format('H:i'))->toBe('05:00');
    });
});

describe('double booking', function () {
    it('refuses the same slot twice', function () {
        attempt($this->bookings, $this->service, $this->staff, $this->customer);

        attempt($this->bookings, $this->service, $this->staff, Customer::factory()->create());
    })->throws(SlotUnavailableException::class);

    it('refuses a slot that merely overlaps', function () {
        attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');

        expect(fn () => attempt(
            $this->bookings, $this->service, $this->staff, Customer::factory()->create(), time: '10:30'
        ))->toThrow(SlotUnavailableException::class);
    });

    it('allows a back-to-back booking', function () {
        attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');

        $next = attempt(
            $this->bookings, $this->service, $this->staff, Customer::factory()->create(), time: '11:00'
        );

        expect($next->status)->toBe(BookingStatus::Confirmed);
    });

    it('refuses a back-to-back booking once a buffer is in the way', function () {
        $this->service->update(['buffer_after_minutes' => 15]);

        attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');

        expect(fn () => attempt(
            $this->bookings, $this->service, $this->staff, Customer::factory()->create(), time: '11:00'
        ))->toThrow(SlotUnavailableException::class);
    });

    it('lets a different staff member take the same time', function () {
        $other = staffMember();
        $this->service->staff()->attach($other);

        attempt($this->bookings, $this->service, $this->staff, $this->customer);
        $second = attempt($this->bookings, $this->service, $other, Customer::factory()->create());

        expect($second->status)->toBe(BookingStatus::Confirmed);
    });

    it('frees the slot again once cancelled', function () {
        $first = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        $this->bookings->cancel($first);

        $second = attempt($this->bookings, $this->service, $this->staff, Customer::factory()->create());

        expect($second->status)->toBe(BookingStatus::Confirmed);
    });

    it('reports why the slot was refused', function () {
        attempt($this->bookings, $this->service, $this->staff, $this->customer);

        try {
            attempt($this->bookings, $this->service, $this->staff, Customer::factory()->create());
        } catch (SlotUnavailableException $e) {
            expect($e->reason)->toBe(SlotUnavailableException::ALREADY_BOOKED);

            return;
        }

        $this->fail('Expected the second booking to be refused.');
    });
});

describe('slot validation', function () {
    it('refuses a time outside working hours', function () {
        expect(fn () => attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '20:00'))
            ->toThrow(SlotUnavailableException::class);
    });

    it('refuses a start that is not on the offered grid', function () {
        try {
            attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:07');
        } catch (SlotUnavailableException $e) {
            expect($e->reason)->toBe(SlotUnavailableException::NOT_ON_SLOT_GRID);

            return;
        }

        $this->fail('Expected an off-grid start time to be refused.');
    });

    it('refuses an appointment that would overrun the end of the shift', function () {
        expect(fn () => attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '16:30'))
            ->toThrow(SlotUnavailableException::class);
    });

    it('refuses a staff member who does not offer the service', function () {
        $stranger = staffMember();

        try {
            attempt($this->bookings, $this->service, $stranger, $this->customer);
        } catch (SlotUnavailableException $e) {
            expect($e->reason)->toBe(SlotUnavailableException::STAFF_CANNOT_PERFORM);

            return;
        }

        $this->fail('Expected an unqualified staff member to be refused.');
    });

    it('refuses an inactive service', function () {
        $this->service->update(['is_active' => false]);

        expect(fn () => attempt($this->bookings, $this->service, $this->staff, $this->customer))
            ->toThrow(SlotUnavailableException::class);
    });

    it('refuses a booking inside the notice period', function () {
        $this->service->update(['min_notice_minutes' => 240]);
        $this->travelTo(slotAt('08:00'));

        try {
            attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');
        } catch (SlotUnavailableException $e) {
            expect($e->reason)->toBe(SlotUnavailableException::TOO_SOON);

            return;
        }

        $this->fail('Expected a short-notice booking to be refused.');
    });

    it('refuses a booking beyond the horizon', function () {
        $this->service->update(['max_advance_days' => 1]);

        try {
            attempt($this->bookings, $this->service, $this->staff, $this->customer);
        } catch (SlotUnavailableException $e) {
            expect($e->reason)->toBe(SlotUnavailableException::TOO_FAR_AHEAD);

            return;
        }

        $this->fail('Expected a far-future booking to be refused.');
    });
});

describe('group sessions', function () {
    it('fills a class up to its capacity and no further', function () {
        $class = bookableService($this->staff, [
            'duration_minutes' => 45,
            'slot_increment_minutes' => 60,
            'capacity' => 2,
        ]);

        attempt($this->bookings, $class, $this->staff, $this->customer);
        attempt($this->bookings, $class, $this->staff, Customer::factory()->create());

        try {
            attempt($this->bookings, $class, $this->staff, Customer::factory()->create());
        } catch (SlotUnavailableException $e) {
            expect($e->reason)->toBe(SlotUnavailableException::AT_CAPACITY);

            return;
        }

        $this->fail('Expected the third booking to be refused.');
    });
});

describe('rescheduling', function () {
    it('moves the booking without colliding with itself', function () {
        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');

        $this->bookings->reschedule($booking, slotAt('14:00'));

        expect($booking->fresh()->starts_at->format('H:i'))->toBe('14:00')
            ->and($booking->fresh()->ends_at->format('H:i'))->toBe('15:00');
    });

    it('can move a booking back onto the time it already holds', function () {
        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');

        $this->bookings->reschedule($booking, slotAt('10:00'));

        expect($booking->fresh()->starts_at->format('H:i'))->toBe('10:00');
    });

    it('refuses to move onto an occupied slot and leaves the original alone', function () {
        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');
        attempt($this->bookings, $this->service, $this->staff, Customer::factory()->create(), time: '14:00');

        expect(fn () => $this->bookings->reschedule($booking, slotAt('14:00')))
            ->toThrow(SlotUnavailableException::class);

        expect($booking->fresh()->starts_at->format('H:i'))->toBe('10:00');
    });

    it('clears the reminder stamp so the new time gets its own reminder', function () {
        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer, time: '10:00');
        $booking->forceFill(['reminder_sent_at' => now()])->save();

        $this->bookings->reschedule($booking, slotAt('14:00'));

        expect($booking->fresh()->reminder_sent_at)->toBeNull();
    });
});

describe('cancelling', function () {
    it('marks who cancelled and why', function () {
        Notification::fake();

        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        $this->bookings->cancel($booking, 'staff', 'Clinician off sick');

        expect($booking->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->cancelled_by)->toBe('staff')
            ->and($booking->cancellation_reason)->toBe('Clinician off sick')
            ->and($booking->cancelled_at)->not->toBeNull();

        Notification::assertSentTo($this->customer, BookingCancelledNotification::class);
    });

    it('refunds a paid deposit when asked', function () {
        $booking = Booking::factory()
            ->forSlot($this->service, $this->staff, slotAt())
            ->for($this->customer)
            ->withPaidDeposit(3000)
            ->create();

        $this->bookings->cancel($booking, refundDeposit: true);

        expect($this->deposits->refunded)->toBe([$booking->reference])
            ->and($booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
    });

    it('leaves the deposit alone when not asked to refund', function () {
        $booking = Booking::factory()
            ->forSlot($this->service, $this->staff, slotAt())
            ->for($this->customer)
            ->withPaidDeposit(3000)
            ->create();

        $this->bookings->cancel($booking, refundDeposit: false);

        expect($this->deposits->refunded)->toBeEmpty()
            ->and($booking->fresh()->payment_status)->toBe(PaymentStatus::Paid);
    });

    it('refuses to cancel a booking that is already cancelled', function () {
        $booking = Booking::factory()
            ->forSlot($this->service, $this->staff, slotAt())
            ->for($this->customer)
            ->cancelled()
            ->create();

        expect(fn () => $this->bookings->cancel($booking))->toThrow(DomainException::class);
    });

    it('dispatches a cancellation event', function () {
        Event::fake([BookingCancelled::class]);

        $booking = Booking::factory()
            ->forSlot($this->service, $this->staff, slotAt())
            ->for($this->customer)
            ->create();

        $this->bookings->cancel($booking);

        Event::assertDispatched(BookingCancelled::class);
    });
});

describe('payment holds', function () {
    it('expires a lapsed hold and puts the slot back on sale', function () {
        $this->service->update(['deposit_type' => 'fixed', 'deposit_value' => 2500]);

        $held = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        expect($held->status)->toBe(BookingStatus::Pending);

        $this->travelTo(now()->addHour());

        expect($this->bookings->releaseExpiredHolds())->toBe(1)
            ->and($held->fresh()->status)->toBe(BookingStatus::Expired);

        // And the slot can be taken by somebody else.
        $replacement = attempt($this->bookings, $this->service, $this->staff, Customer::factory()->create());

        expect($replacement->status)->toBe(BookingStatus::Pending);
    });

    it('leaves a hold that has not lapsed', function () {
        $this->service->update(['deposit_type' => 'fixed', 'deposit_value' => 2500]);

        attempt($this->bookings, $this->service, $this->staff, $this->customer);

        expect($this->bookings->releaseExpiredHolds())->toBe(0);
    });

    it('confirms the booking once the deposit lands', function () {
        Notification::fake();

        $this->service->update(['deposit_type' => 'fixed', 'deposit_value' => 2500]);

        $booking = attempt($this->bookings, $this->service, $this->staff, $this->customer);

        $this->bookings->markDepositPaid($booking, 'pi_real_123');

        expect($booking->fresh())
            ->status->toBe(BookingStatus::Confirmed)
            ->payment_status->toBe(PaymentStatus::Paid)
            ->stripe_payment_intent_id->toBe('pi_real_123')
            ->hold_expires_at->toBeNull();

        Notification::assertSentTo($this->customer, BookingConfirmedNotification::class);
    });
});

it('gives every booking a distinct, readable reference', function () {
    $references = collect(range(0, 4))->map(fn (int $i) => attempt(
        $this->bookings,
        $this->service,
        $this->staff,
        Customer::factory()->create(),
        time: sprintf('%02d:00', 9 + $i),
    )->reference);

    expect($references->unique())->toHaveCount(5)
        ->and($references->every(fn (string $ref) => (bool) preg_match('/^BK-[A-Z2-9]{8}$/', $ref)))
        ->toBeTrue();
});
