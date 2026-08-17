<?php

namespace Tests\Support;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Services\Payments\DepositService;
use Laravel\Cashier\Payment;

/**
 * Stands in for Stripe. Records what would have been charged and moves the
 * booking through the same states the real service would, without a network
 * call — the payment states are what the booking logic cares about, and they
 * are worth asserting on directly.
 */
class FakeDepositService extends DepositService
{
    /** @var array<int, string> */
    public array $requested = [];

    /** @var array<int, string> */
    public array $refunded = [];

    public function requestDeposit(Booking $booking): ?Payment
    {
        if ($booking->deposit_amount <= 0) {
            return null;
        }

        $this->requested[] = $booking->reference;

        $booking->forceFill([
            'stripe_payment_intent_id' => 'pi_fake_'.$booking->reference,
            'payment_status' => PaymentStatus::Pending,
        ])->save();

        return null;
    }

    public function clientSecretFor(Booking $booking): ?string
    {
        return $booking->stripe_payment_intent_id
            ? $booking->stripe_payment_intent_id.'_secret_fake'
            : null;
    }

    public function refund(Booking $booking): void
    {
        if ($booking->payment_status !== PaymentStatus::Paid) {
            return;
        }

        $this->refunded[] = $booking->reference;

        $booking->forceFill(['payment_status' => PaymentStatus::Refunded])->save();
    }
}
