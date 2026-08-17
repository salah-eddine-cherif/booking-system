<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe, not the browser, is what confirms a booking.
 *
 * The customer's tab can close, crash or navigate away between paying and being
 * redirected back, so the webhook is treated as the only trustworthy signal that
 * money actually moved. Everything here is idempotent, because Stripe retries.
 */
class StripeWebhookController extends CashierWebhookController
{
    public function __construct(private readonly BookingService $bookings)
    {
        parent::__construct();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentIntentSucceeded(array $payload): Response
    {
        $booking = $this->bookingFrom($payload);

        if ($booking && $booking->payment_status !== PaymentStatus::Paid) {
            $this->bookings->markDepositPaid($booking, $payload['data']['object']['id']);
        }

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handlePaymentIntentPaymentFailed(array $payload): Response
    {
        $booking = $this->bookingFrom($payload);

        // The hold is deliberately left alone: the customer may well retry with
        // another card, and bookings:release-holds frees the slot if they don't.
        if ($booking && $booking->status === BookingStatus::Pending) {
            $booking->forceFill(['payment_status' => PaymentStatus::Failed])->save();
        }

        return $this->successMethod();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleChargeRefunded(array $payload): Response
    {
        $intentId = $payload['data']['object']['payment_intent'] ?? null;

        $booking = $intentId
            ? Booking::where('stripe_payment_intent_id', $intentId)->first()
            : null;

        if ($booking && $booking->payment_status === PaymentStatus::Paid) {
            $booking->forceFill(['payment_status' => PaymentStatus::Refunded])->save();
        }

        return $this->successMethod();
    }

    /**
     * Resolve the booking from the metadata written when the intent was created,
     * falling back to the intent id for anything created out of band.
     *
     * @param  array<string, mixed>  $payload
     */
    private function bookingFrom(array $payload): ?Booking
    {
        $object = $payload['data']['object'] ?? [];

        if ($reference = $object['metadata']['booking_reference'] ?? null) {
            return Booking::where('reference', $reference)->first();
        }

        return isset($object['id'])
            ? Booking::where('stripe_payment_intent_id', $object['id'])->first()
            : null;
    }
}
