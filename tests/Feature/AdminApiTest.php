<?php

use App\Enums\BookingStatus;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-02-27 00:00', 'UTC'));

    Notification::fake();
    fakeDeposits();

    $this->admin = User::factory()->admin()->notBookable()->create(['password' => 'secret-password']);
    $this->ana = staffMember();
    $this->marcus = staffMember();

    $this->service = bookableService($this->ana, ['slug' => 'follow-up', 'duration_minutes' => 60]);
    $this->service->staff()->attach($this->marcus);
});

describe('token issuing', function () {
    it('issues a token for valid credentials', function () {
        $this->postJson('/api/v1/auth/token', [
            'email' => $this->admin->email,
            'password' => 'secret-password',
            'device_name' => 'admin-spa',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.role', 'admin');
    });

    it('rejects a wrong password without saying which field was wrong', function () {
        $this->postJson('/api/v1/auth/token', [
            'email' => $this->admin->email,
            'password' => 'wrong',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('rejects an unknown email the same way', function () {
        $this->postJson('/api/v1/auth/token', [
            'email' => 'nobody@example.com',
            'password' => 'secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    });

    it('locks the admin API behind a token', function () {
        $this->getJson('/api/v1/admin/bookings')->assertUnauthorized();
    });
});

describe('booking list', function () {
    beforeEach(function () {
        $this->anasBooking = Booking::factory()
            ->forSlot($this->service, $this->ana, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'))
            ->create();

        $this->marcusBooking = Booking::factory()
            ->forSlot($this->service, $this->marcus, CarbonImmutable::parse('2026-03-02 11:00', 'UTC'))
            ->create();
    });

    it('shows an admin the whole calendar', function () {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/bookings')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('shows a staff member only their own column', function () {
        Sanctum::actingAs($this->ana);

        $response = $this->getJson('/api/v1/admin/bookings')->assertOk()->assertJsonCount(1, 'data');

        expect($response->json('data.0.reference'))->toBe($this->anasBooking->reference);
    });

    it('filters by date range and status', function () {
        Sanctum::actingAs($this->admin);

        $this->marcusBooking->forceFill(['status' => BookingStatus::Cancelled])->save();

        $this->getJson('/api/v1/admin/bookings?status=confirmed')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/bookings?from=2026-03-03')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('stops a staff member reading another’s booking', function () {
        Sanctum::actingAs($this->ana);

        $this->getJson("/api/v1/admin/bookings/{$this->marcusBooking->reference}")->assertForbidden();
        $this->getJson("/api/v1/admin/bookings/{$this->anasBooking->reference}")->assertOk();
    });
});

describe('updating a booking', function () {
    beforeEach(function () {
        $this->booking = Booking::factory()
            ->forSlot($this->service, $this->ana, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'))
            ->create();
    });

    it('marks a booking as completed and stores staff notes', function () {
        Sanctum::actingAs($this->ana);

        $this->patchJson("/api/v1/admin/bookings/{$this->booking->reference}", [
            'status' => 'completed',
            'staff_notes' => 'Responded well to treatment.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.staff_notes', 'Responded well to treatment.');
    });

    it('refuses a status the staff should not set directly', function () {
        Sanctum::actingAs($this->ana);

        $this->patchJson("/api/v1/admin/bookings/{$this->booking->reference}", ['status' => 'expired'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    });

    it('reschedules onto a free slot', function () {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/bookings/{$this->booking->reference}/reschedule", [
            'starts_at' => '2026-03-02T14:00:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-03-02T14:00:00+00:00');
    });

    it('refuses to reschedule onto a taken slot', function () {
        Sanctum::actingAs($this->admin);

        Booking::factory()
            ->forSlot($this->service, $this->ana, CarbonImmutable::parse('2026-03-02 14:00', 'UTC'))
            ->create();

        $this->postJson("/api/v1/admin/bookings/{$this->booking->reference}/reschedule", [
            'starts_at' => '2026-03-02T14:00:00Z',
        ])->assertStatus(409);
    });

    it('cancels on behalf of the customer', function () {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/admin/bookings/{$this->booking->reference}", [
            'reason' => 'Clinician unwell',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        expect($this->booking->fresh()->cancelled_by)->toBe('staff');
    });

    it('lets staff cancel inside the customer notice window', function () {
        Sanctum::actingAs($this->ana);

        // Two hours out, well inside the 24 hours a customer would need.
        $this->travelTo(CarbonImmutable::parse('2026-03-02 08:00', 'UTC'));

        $this->deleteJson("/api/v1/admin/bookings/{$this->booking->reference}")->assertOk();
    });
});

describe('service management', function () {
    it('lets an admin create a service and assign staff', function () {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/services', [
            'name' => 'Sports Massage',
            'duration_minutes' => 45,
            'slot_increment_minutes' => 15,
            'price_amount' => 6500,
            'currency' => 'eur',
            'deposit_type' => 'percentage',
            'deposit_value' => 20,
            'staff_ids' => [$this->ana->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'sports-massage')
            ->assertJsonPath('data.deposit.amount', 1300)
            ->assertJsonPath('data.staff.0.id', $this->ana->id);
    });

    it('stops a non-admin creating services', function () {
        Sanctum::actingAs($this->ana);

        $this->postJson('/api/v1/admin/services', [
            'name' => 'Sports Massage',
            'duration_minutes' => 45,
        ])->assertForbidden();
    });

    it('rejects a percentage deposit over 100', function () {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/services', [
            'name' => 'Odd Service',
            'duration_minutes' => 30,
            'deposit_type' => 'percentage',
            'deposit_value' => 150,
        ])->assertUnprocessable()->assertJsonValidationErrors('deposit_value');
    });

    it('deactivates rather than deletes a service that has bookings', function () {
        Sanctum::actingAs($this->admin);

        Booking::factory()
            ->forSlot($this->service, $this->ana, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'))
            ->create();

        $this->deleteJson("/api/v1/admin/services/{$this->service->slug}")->assertOk();

        expect($this->service->fresh()->is_active)->toBeFalse()
            ->and(Service::whereKey($this->service->id)->exists())->toBeTrue();
    });

    it('deletes a service that was never booked', function () {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/admin/services/{$this->service->slug}")->assertNoContent();

        expect(Service::whereKey($this->service->id)->exists())->toBeFalse();
    });
});

describe('schedule management', function () {
    it('returns the rules, exceptions and resolved working hours', function () {
        Sanctum::actingAs($this->ana);

        AvailabilityException::factory()->blocking('2026-03-02', '12:00:00', '13:00:00')->for($this->ana)->create();

        $response = $this->getJson("/api/v1/admin/staff/{$this->ana->id}/schedule?from=2026-03-02&to=2026-03-02")
            ->assertOk()
            ->assertJsonCount(5, 'data.rules')
            ->assertJsonCount(1, 'data.exceptions');

        // The lunch break splits the day into two working blocks.
        expect($response->json('data.working_hours'))->toHaveCount(2);
    });

    it('adds and removes a weekly rule', function () {
        Sanctum::actingAs($this->ana);

        $ruleId = $this->postJson("/api/v1/admin/staff/{$this->ana->id}/rules", [
            'day_of_week' => 6,
            'start_time' => '10:00',
            'end_time' => '14:00',
        ])->assertCreated()->json('data.id');

        expect(AvailabilityRule::whereKey($ruleId)->exists())->toBeTrue();

        $this->deleteJson("/api/v1/admin/staff/{$this->ana->id}/rules/{$ruleId}")->assertNoContent();

        expect(AvailabilityRule::whereKey($ruleId)->exists())->toBeFalse();
    });

    it('stops a staff member editing someone else’s schedule', function () {
        Sanctum::actingAs($this->ana);

        $this->postJson("/api/v1/admin/staff/{$this->marcus->id}/rules", [
            'day_of_week' => 6,
            'start_time' => '10:00',
            'end_time' => '14:00',
        ])->assertForbidden();
    });

    it('lets an admin edit anyone’s schedule', function () {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/admin/staff/{$this->marcus->id}/rules", [
            'day_of_week' => 6,
            'start_time' => '10:00',
            'end_time' => '14:00',
        ])->assertCreated();
    });

    it('requires both times on an extra availability window', function () {
        Sanctum::actingAs($this->ana);

        $this->postJson("/api/v1/admin/staff/{$this->ana->id}/exceptions", [
            'date' => '2026-03-07',
            'is_available' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('start_time');
    });

    it('accepts a whole-day block with no times', function () {
        Sanctum::actingAs($this->ana);

        $this->postJson("/api/v1/admin/staff/{$this->ana->id}/exceptions", [
            'date' => '2026-03-04',
            'is_available' => false,
            'reason' => 'Annual leave',
        ])
            ->assertCreated()
            ->assertJsonPath('data.whole_day', true);
    });

    it('will not delete a rule belonging to another staff member', function () {
        Sanctum::actingAs($this->admin);

        $marcusRule = $this->marcus->availabilityRules()->first();

        $this->deleteJson("/api/v1/admin/staff/{$this->ana->id}/rules/{$marcusRule->id}")
            ->assertNotFound();
    });
});
