<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingConfirmedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->travelTo(CarbonImmutable::parse('2026-02-27 00:00', 'UTC'));

    $this->staff = staffMember();
    $this->customer = Customer::factory()->create();
    $this->service = bookableService($this->staff, [
        'deposit_type' => 'fixed',
        'deposit_value' => 2500,
    ]);

    $this->booking = Booking::factory()
        ->forSlot($this->service, $this->staff, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'))
        ->for($this->customer)
        ->awaitingDeposit(2500)
        ->create(['stripe_payment_intent_id' => 'pi_test_123']);
});

/**
 * @return array<string, mixed>
 */
function stripeEvent(string $type, array $object = []): array
{
    return [
        'id' => 'evt_test_'.uniqid(),
        'type' => $type,
        'data' => ['object' => array_merge([
            'id' => 'pi_test_123',
            'object' => 'payment_intent',
            'amount' => 2500,
            'currency' => 'usd',
            'metadata' => ['booking_reference' => test()->booking->reference],
        ], $object)],
    ];
}

it('confirms the booking when the deposit succeeds', function () {
    $this->postJson('/api/stripe/webhook', stripeEvent('payment_intent.succeeded'))
        ->assertOk();

    expect($this->booking->fresh())
        ->status->toBe(BookingStatus::Confirmed)
        ->payment_status->toBe(PaymentStatus::Paid)
        ->hold_expires_at->toBeNull()
        ->confirmed_at->not->toBeNull();

    Notification::assertSentTo($this->customer, BookingConfirmedNotification::class);
});

it('is idempotent, because Stripe retries', function () {
    $this->postJson('/api/stripe/webhook', stripeEvent('payment_intent.succeeded'))->assertOk();
    $this->postJson('/api/stripe/webhook', stripeEvent('payment_intent.succeeded'))->assertOk();

    Notification::assertSentToTimes($this->customer, BookingConfirmedNotification::class, 1);
});

it('finds the booking by payment intent when metadata is missing', function () {
    $payload = stripeEvent('payment_intent.succeeded');
    unset($payload['data']['object']['metadata']);

    $this->postJson('/api/stripe/webhook', $payload)->assertOk();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('records a failed payment but keeps the hold', function () {
    $this->postJson('/api/stripe/webhook', stripeEvent('payment_intent.payment_failed'))
        ->assertOk();

    // The customer may still retry with another card; the hold sweep is what
    // eventually frees the slot.
    expect($this->booking->fresh())
        ->payment_status->toBe(PaymentStatus::Failed)
        ->status->toBe(BookingStatus::Pending)
        ->hold_expires_at->not->toBeNull();
});

it('marks a refunded charge', function () {
    $this->booking->forceFill([
        'status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Paid,
    ])->save();

    $this->postJson('/api/stripe/webhook', [
        'id' => 'evt_refund',
        'type' => 'charge.refunded',
        'data' => ['object' => ['id' => 'ch_test', 'payment_intent' => 'pi_test_123']],
    ])->assertOk();

    expect($this->booking->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

it('shrugs off an event for an unknown booking', function () {
    $payload = stripeEvent('payment_intent.succeeded', [
        'id' => 'pi_unknown',
        'metadata' => ['booking_reference' => 'BK-NOTHERE'],
    ]);

    $this->postJson('/api/stripe/webhook', $payload)->assertOk();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Pending);
});

it('ignores event types it does not handle', function () {
    $this->postJson('/api/stripe/webhook', [
        'id' => 'evt_other',
        'type' => 'invoice.created',
        'data' => ['object' => ['id' => 'in_123']],
    ])->assertOk();

    expect($this->booking->fresh()->status)->toBe(BookingStatus::Pending);
});
