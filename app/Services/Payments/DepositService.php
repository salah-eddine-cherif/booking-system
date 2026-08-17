<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use Laravel\Cashier\Payment;

/**
 * The deposit half of a booking.
 *
 * A PaymentIntent is created but deliberately not confirmed server-side: the
 * client confirms it with Stripe Elements, which keeps SCA/3-D Secure handling
 * where it belongs. The slot is held meanwhile, and the webhook is what actually
 * promotes the booking to confirmed — never the browser, which can always
 * navigate away mid-flow.
 */
class DepositService
{
    /**
     * Open a PaymentIntent for the booking's deposit.
     */
    public function requestDeposit(Booking $booking): ?Payment
    {
        if ($booking->deposit_amount <= 0) {
            return null;
        }

        $booking->loadMissing(['customer', 'service']);

        $customer = $booking->customer;
        $customer->createOrGetStripeCustomer();

        $payment = $customer->createPayment($booking->deposit_amount, [
            'currency' => $booking->currency,
            'description' => $this->describe($booking),
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'booking_reference' => $booking->reference,
            ],
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        $booking->forceFill([
            'stripe_payment_intent_id' => $payment->id,
            'payment_status' => $this->statusFor($payment),
        ])->save();

        return $payment;
    }

    /**
     * The secret the browser needs to confirm the deposit.
     */
    public function clientSecretFor(Booking $booking): ?string
    {
        if (! $booking->stripe_payment_intent_id) {
            return null;
        }

        return $booking->loadMissing('customer')->customer
            ->findPayment($booking->stripe_payment_intent_id)
            ?->clientSecret();
    }

    /**
     * Refund a paid deposit in full.
     */
    public function refund(Booking $booking): void
    {
        if ($booking->payment_status !== PaymentStatus::Paid || ! $booking->stripe_payment_intent_id) {
            return;
        }

        $booking->loadMissing('customer')->customer->refund($booking->stripe_payment_intent_id);

        $booking->forceFill(['payment_status' => PaymentStatus::Refunded])->save();
    }

    /**
     * Map a Stripe PaymentIntent status onto our own.
     */
    public function statusFor(Payment $payment): PaymentStatus
    {
        return match (true) {
            $payment->isSucceeded() => PaymentStatus::Paid,
            $payment->isCanceled() => PaymentStatus::Failed,
            $payment->requiresAction(), $payment->requiresConfirmation() => PaymentStatus::RequiresAction,
            default => PaymentStatus::Pending,
        };
    }

    private function describe(Booking $booking): string
    {
        $when = $booking->localStartsAt()->format('D j M Y, H:i');

        return "Deposit for {$booking->service->name} on {$when} ({$booking->reference})";
    }
}
