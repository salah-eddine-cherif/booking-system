<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-27 00:00', 'UTC'));

    Notification::fake();
    $this->deposits = fakeDeposits();

    $this->staff = staffMember();
    $this->service = bookableService($this->staff, [
        'name' => 'Follow-up Session',
        'slug' => 'follow-up',
        'duration_minutes' => 60,
        'slot_increment_minutes' => 60,
        'price_amount' => 9000,
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'service' => 'follow-up',
        'staff_id' => test()->staff->id,
        'starts_at' => '2026-03-02T10:00:00Z',
        'timezone' => 'Europe/Lisbon',
        'customer' => [
            'name' => 'Joana Silva',
            'email' => 'joana@example.com',
            'phone' => '+351912345678',
        ],
        'notes' => 'Ground floor room please.',
    ], $overrides);
}

describe('catalogue', function () {
    it('lists active services with their staff', function () {
        $this->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'follow-up')
            ->assertJsonPath('data.0.price.amount', 9000)
            ->assertJsonPath('data.0.deposit.required', false)
            ->assertJsonPath('data.0.staff.0.id', $this->staff->id);
    });

    it('hides inactive services', function () {
        $this->service->update(['is_active' => false]);

        $this->getJson('/api/v1/services')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/services/follow-up')->assertNotFound();
    });
});

describe('availability endpoint', function () {
    it('returns slots grouped by local date', function () {
        $response = $this->getJson('/api/v1/services/follow-up/availability?from=2026-03-02&to=2026-03-02&timezone=UTC')
            ->assertOk()
            ->assertJsonPath('data.timezone', 'UTC');

        $slots = $response->json('data.days.2026-03-02');

        expect($slots)->toHaveCount(8)
            ->and($slots[0]['starts_at'])->toBe('2026-03-02T09:00:00+00:00')
            ->and($slots[0]['remaining'])->toBe(1);
    });

    it('renders slots in the requested timezone', function () {
        $response = $this->getJson('/api/v1/services/follow-up/availability?from=2026-03-02&to=2026-03-02&timezone=America/New_York')
            ->assertOk();

        // The staff member works 09:00-17:00 UTC, which is 04:00-12:00 in New York.
        $days = $response->json('data.days');

        expect(array_keys($days))->toBe(['2026-03-02'])
            ->and($days['2026-03-02'][0]['starts_at_local'])->toBe('2026-03-02T04:00:00-05:00');
    });

    it('excludes a slot that has just been taken', function () {
        Booking::factory()
            ->forSlot($this->service, $this->staff, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'))
            ->create();

        $starts = collect(
            $this->getJson('/api/v1/services/follow-up/availability?from=2026-03-02&to=2026-03-02')
                ->json('data.days.2026-03-02')
        )->pluck('starts_at');

        expect($starts)->not->toContain('2026-03-02T10:00:00+00:00');
    });

    it('validates the requested range', function () {
        $this->getJson('/api/v1/services/follow-up/availability?from=2026-03-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/services/follow-up/availability?from=2026-03-02&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        // More than the 62-day cap.
        $this->getJson('/api/v1/services/follow-up/availability?from=2026-03-02&to=2026-09-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });
});

describe('creating a booking', function () {
    it('books a slot and returns the reference', function () {
        $response = $this->postJson('/api/v1/bookings', bookingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.timezone', 'Europe/Lisbon')
            ->assertJsonPath('payment', null);

        $this->assertDatabaseHas('bookings', [
            'reference' => $response->json('data.reference'),
            'user_id' => $this->staff->id,
            'status' => BookingStatus::Confirmed->value,
        ]);

        $this->assertDatabaseHas('customers', ['email' => 'joana@example.com']);
    });

    it('interprets a bare local time in the supplied timezone', function () {
        // 10:00 in Lisbon is 10:00 UTC in March, before the clocks change.
        $reference = $this->postJson('/api/v1/bookings', bookingPayload([
            'starts_at' => '2026-03-02 10:00:00',
            'timezone' => 'Europe/Lisbon',
        ]))->assertCreated()->json('data.reference');

        expect(Booking::where('reference', $reference)->sole()->starts_at->format('H:i'))
            ->toBe('10:00');
    });

    it('returns a payment intent when a deposit is due', function () {
        $this->service->update(['deposit_type' => 'fixed', 'deposit_value' => 2500]);

        $this->postJson('/api/v1/bookings', bookingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('payment.amount', 2500)
            ->assertJsonStructure(['payment' => ['client_secret', 'hold_expires_at']]);
    });

    it('answers 409 with a reason when the slot has gone', function () {
        $this->postJson('/api/v1/bookings', bookingPayload())->assertCreated();

        $this->postJson('/api/v1/bookings', bookingPayload([
            'customer' => ['email' => 'someone.else@example.com'],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_booked');
    });

    it('answers 409 when the time is outside working hours', function () {
        $this->postJson('/api/v1/bookings', bookingPayload(['starts_at' => '2026-03-02T22:00:00Z']))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'outside_working_hours');
    });

    it('validates the payload', function () {
        $this->postJson('/api/v1/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service', 'staff_id', 'starts_at', 'customer.name', 'customer.email']);

        $this->postJson('/api/v1/bookings', bookingPayload(['timezone' => 'Mars/Olympus']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    });

    it('reuses the customer record for a repeat email address', function () {
        $this->postJson('/api/v1/bookings', bookingPayload())->assertCreated();
        $this->postJson('/api/v1/bookings', bookingPayload(['starts_at' => '2026-03-02T14:00:00Z']))->assertCreated();

        expect(Customer::where('email', 'joana@example.com')->count())->toBe(1);
    });
});

describe('looking a booking up', function () {
    beforeEach(function () {
        $this->reference = $this->postJson('/api/v1/bookings', bookingPayload())
            ->json('data.reference');
    });

    it('returns the booking to the email address that made it', function () {
        $this->getJson("/api/v1/bookings/{$this->reference}?email=joana@example.com")
            ->assertOk()
            ->assertJsonPath('data.reference', $this->reference)
            ->assertJsonPath('data.service.slug', 'follow-up');
    });

    it('refuses a mismatched email address', function () {
        $this->getJson("/api/v1/bookings/{$this->reference}?email=nosy@example.com")
            ->assertForbidden();

        $this->getJson("/api/v1/bookings/{$this->reference}")->assertForbidden();
    });

    it('hides staff notes from customers', function () {
        Booking::where('reference', $this->reference)->update(['staff_notes' => 'Private note']);

        $this->getJson("/api/v1/bookings/{$this->reference}?email=joana@example.com")
            ->assertOk()
            ->assertJsonMissingPath('data.staff_notes');
    });

    it('404s an unknown reference', function () {
        $this->getJson('/api/v1/bookings/BK-NOTREAL?email=joana@example.com')->assertNotFound();
    });
});

describe('cancelling a booking', function () {
    beforeEach(function () {
        $this->reference = $this->postJson('/api/v1/bookings', bookingPayload())
            ->json('data.reference');
    });

    it('cancels when outside the notice window', function () {
        $this->postJson("/api/v1/bookings/{$this->reference}/cancel", [
            'email' => 'joana@example.com',
            'reason' => 'Something came up',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Something came up');
    });

    it('refuses once inside the notice window', function () {
        // The service asks for 24 hours' notice; move to two hours before.
        $this->travelTo(CarbonImmutable::parse('2026-03-02 08:00', 'UTC'));

        $this->postJson("/api/v1/bookings/{$this->reference}/cancel", ['email' => 'joana@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('cancellation_notice_minutes', 1440);

        expect(Booking::where('reference', $this->reference)->sole()->status)
            ->toBe(BookingStatus::Confirmed);
    });

    it('refuses a mismatched email address', function () {
        $this->postJson("/api/v1/bookings/{$this->reference}/cancel", ['email' => 'nosy@example.com'])
            ->assertForbidden();
    });

    it('refuses to cancel twice', function () {
        $this->postJson("/api/v1/bookings/{$this->reference}/cancel", ['email' => 'joana@example.com'])
            ->assertOk();

        $this->postJson("/api/v1/bookings/{$this->reference}/cancel", ['email' => 'joana@example.com'])
            ->assertUnprocessable();
    });
});
