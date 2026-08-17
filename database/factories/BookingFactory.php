<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc();

        return [
            'service_id' => Service::factory(),
            'user_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'blocked_starts_at' => $startsAt,
            'blocked_ends_at' => $startsAt->addHour(),
            'duration_minutes' => 60,
            'customer_timezone' => 'UTC',
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::NotRequired,
            'price_amount' => 9000,
            'deposit_amount' => 0,
            'currency' => 'usd',
            'confirmed_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Place the booking on a real slot, deriving the blocked range from the
     * service exactly as BookingService would.
     */
    public function forSlot(Service $service, User $staff, CarbonImmutable $startsAt): static
    {
        $startsAt = $startsAt->utc();

        return $this->state(fn (array $attributes) => [
            'service_id' => $service->id,
            'user_id' => $staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($service->duration_minutes),
            'blocked_starts_at' => $startsAt->subMinutes($service->buffer_before_minutes),
            'blocked_ends_at' => $startsAt->addMinutes(
                $service->duration_minutes + $service->buffer_after_minutes
            ),
            'duration_minutes' => $service->duration_minutes,
            'price_amount' => $service->price_amount,
            'currency' => $service->currency,
        ]);
    }

    /** Held while a deposit is outstanding. */
    public function awaitingDeposit(int $amount = 2500, ?CarbonImmutable $holdUntil = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'deposit_amount' => $amount,
            'hold_expires_at' => $holdUntil ?? CarbonImmutable::now()->addMinutes(15),
            'confirmed_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancelled_by' => 'customer',
            'confirmed_at' => null,
        ]);
    }

    public function withPaidDeposit(int $amount = 2500): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentStatus::Paid,
            'deposit_amount' => $amount,
            'stripe_payment_intent_id' => 'pi_'.fake()->unique()->lexify('??????????????'),
        ]);
    }
}
