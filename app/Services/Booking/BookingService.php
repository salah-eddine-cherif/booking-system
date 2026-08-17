<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Events\BookingRescheduled;
use App\Events\DepositRequested;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Availability\AvailabilityCalculator;
use App\Services\Payments\DepositService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creating, moving and cancelling appointments.
 *
 * The one thing this class exists to get right is that two customers can never
 * end up in the same slot. Checking availability and then inserting a row is a
 * classic check-then-act race, so both halves happen inside a transaction that
 * first takes a lock on the staff member's row. Every concurrent attempt against
 * the same calendar therefore queues behind that lock and re-runs the
 * availability check against committed state.
 *
 * A row lock rather than a range/exclusion constraint because it is the one
 * mechanism that behaves identically on MySQL, PostgreSQL and SQLite. It
 * serialises writes per staff member, which is exactly the granularity needed:
 * two staff members can still be booked in parallel.
 */
class BookingService
{
    public function __construct(
        private readonly AvailabilityCalculator $availability,
        private readonly DepositService $deposits,
    ) {}

    /**
     * Secure a slot and create the booking.
     *
     * Bookings that need a deposit are created as pending with a hold; the slot
     * is released again by bookings:release-holds if payment never lands.
     *
     * @throws SlotUnavailableException
     */
    public function book(PendingBooking $pending, ?CarbonImmutable $now = null): Booking
    {
        $now ??= CarbonImmutable::now();

        $booking = DB::transaction(function () use ($pending, $now) {
            // Serialise every booking attempt against this staff member.
            User::query()->whereKey($pending->staff->id)->lockForUpdate()->first();

            // Re-checked *inside* the lock: whatever was true when the customer
            // loaded the page is irrelevant, only committed state counts.
            $this->availability->assertBookable(
                $pending->service,
                $pending->staff,
                $pending->startsAt,
                $now,
            );

            return $this->persist($pending, $now);
        }, attempts: 3);

        // Stripe is deliberately called after the transaction commits: an external
        // request must never be holding a database lock, and a failure here simply
        // leaves the hold to expire on its own.
        if ($booking->deposit_amount > 0) {
            $this->deposits->requestDeposit($booking);

            DepositRequested::dispatch($booking);
        } else {
            BookingConfirmed::dispatch($booking);
        }

        return $booking->refresh();
    }

    /**
     * Move an existing booking to a new time and/or staff member.
     *
     * @throws SlotUnavailableException
     */
    public function reschedule(
        Booking $booking,
        CarbonImmutable $startsAt,
        ?User $staff = null,
        ?CarbonImmutable $now = null,
    ): Booking {
        $now ??= CarbonImmutable::now();

        $booking->loadMissing(['service', 'staff']);

        $staff ??= $booking->staff;
        $service = $booking->service;
        $original = $booking->starts_at;

        return DB::transaction(function () use ($booking, $service, $staff, $startsAt, $now, $original) {
            User::query()->whereKey($staff->id)->lockForUpdate()->first();

            // Ignore this booking's own footprint, or it would collide with itself.
            $this->availability->assertBookable($service, $staff, $startsAt, $now, $booking->id);

            $blocked = $this->availability->blockedRangeFor($service, $startsAt);

            $booking->forceFill([
                'user_id' => $staff->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($service->duration_minutes),
                'blocked_starts_at' => $blocked->start,
                'blocked_ends_at' => $blocked->end,
                // A moved booking needs a fresh reminder.
                'reminder_sent_at' => null,
            ])->save();

            BookingRescheduled::dispatch($booking, $original);

            return $booking;
        }, attempts: 3);
    }

    /**
     * Promote a paid or deposit-free booking to confirmed.
     */
    public function confirm(Booking $booking, ?CarbonImmutable $now = null): Booking
    {
        if ($booking->status === BookingStatus::Confirmed) {
            return $booking;
        }

        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => $now ?? CarbonImmutable::now(),
            'hold_expires_at' => null,
        ])->save();

        BookingConfirmed::dispatch($booking);

        return $booking;
    }

    /**
     * Cancel a booking and free the slot.
     *
     * @param  string  $cancelledBy  "customer", "staff" or "system"
     */
    public function cancel(
        Booking $booking,
        string $cancelledBy = 'customer',
        ?string $reason = null,
        bool $refundDeposit = false,
        ?CarbonImmutable $now = null,
    ): Booking {
        if (! $booking->isCancellable()) {
            throw new \DomainException("A {$booking->status->value} booking cannot be cancelled.");
        }

        $booking->loadMissing(['service', 'staff', 'customer']);

        if ($refundDeposit && $booking->payment_status === PaymentStatus::Paid) {
            $this->deposits->refund($booking);
        }

        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => $now ?? CarbonImmutable::now(),
            'cancelled_by' => $cancelledBy,
            'cancellation_reason' => $reason,
        ])->save();

        BookingCancelled::dispatch($booking);

        return $booking;
    }

    /**
     * Record a successful deposit and confirm the appointment.
     */
    public function markDepositPaid(Booking $booking, ?string $paymentIntentId = null): Booking
    {
        $booking->payment_status = PaymentStatus::Paid;

        if ($paymentIntentId !== null) {
            $booking->stripe_payment_intent_id = $paymentIntentId;
        }

        $booking->save();

        return $this->confirm($booking);
    }

    /**
     * Release slots whose payment hold has lapsed.
     *
     * @return int Number of bookings expired.
     */
    public function releaseExpiredHolds(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return Booking::query()
            ->where('status', BookingStatus::Pending)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', $now)
            ->get()
            ->each(function (Booking $booking) use ($now) {
                $booking->forceFill([
                    'status' => BookingStatus::Expired,
                    'cancelled_at' => $now,
                    'cancelled_by' => 'system',
                    'cancellation_reason' => 'Deposit was not paid before the hold expired.',
                ])->save();
            })
            ->count();
    }

    /**
     * Write the booking row, snapshotting the service settings that must not
     * change underneath it later.
     */
    private function persist(PendingBooking $pending, CarbonImmutable $now): Booking
    {
        $service = $pending->service;
        $price = $pending->priceAmount();
        $deposit = $pending->depositAmount();
        $blocked = $this->availability->blockedRangeFor($service, $pending->startsAt);
        $needsDeposit = $deposit > 0;

        $booking = new Booking;

        // forceFill rather than create(): every value here is derived by this
        // service, never taken from request input, and confirmed_at deliberately
        // stays out of the model's fillable list.
        $booking->forceFill([
            'service_id' => $service->id,
            'user_id' => $pending->staff->id,
            'customer_id' => $pending->customer->id,
            'starts_at' => $pending->startsAt,
            'ends_at' => $pending->startsAt->addMinutes($service->duration_minutes),
            'blocked_starts_at' => $blocked->start,
            'blocked_ends_at' => $blocked->end,
            'duration_minutes' => $service->duration_minutes,
            'customer_timezone' => $pending->customerTimezone,
            'status' => $needsDeposit ? BookingStatus::Pending : BookingStatus::Confirmed,
            'payment_status' => $needsDeposit ? PaymentStatus::Pending : PaymentStatus::NotRequired,
            'price_amount' => $price,
            'deposit_amount' => $deposit,
            'currency' => $service->currency,
            'hold_expires_at' => $needsDeposit
                ? $now->addMinutes($service->payment_hold_minutes)
                : null,
            'confirmed_at' => $needsDeposit ? null : $now,
            'customer_notes' => $pending->notes,
        ])->save();

        return $booking;
    }
}
