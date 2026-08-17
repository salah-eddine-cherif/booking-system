<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // Human-quotable code, used for guest lookup and cancellation links.
            $table->string('reference', 16)->unique();

            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // The appointment the customer sees. Always UTC.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // The appointment plus its buffers: the range that actually blocks the
            // calendar. Denormalised so that later edits to the service's buffer
            // settings never silently move an existing booking's footprint, and so
            // overlap checks stay a single indexed range query.
            $table->dateTime('blocked_starts_at');
            $table->dateTime('blocked_ends_at');

            // Snapshot of the service settings at the time of booking.
            $table->unsignedSmallInteger('duration_minutes');

            // The zone the customer booked in, so confirmations and reminders read
            // back in the same wall-clock time they originally saw.
            $table->string('customer_timezone')->default('UTC');

            $table->string('status')->default(BookingStatus::Pending->value);
            $table->string('payment_status')->default(PaymentStatus::NotRequired->value);

            $table->unsignedInteger('price_amount')->default(0);
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('stripe_payment_intent_id')->nullable()->index();

            // While a deposit is outstanding the slot is held until this moment.
            $table->dateTime('hold_expires_at')->nullable();

            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->dateTime('reminder_sent_at')->nullable();

            $table->text('customer_notes')->nullable();
            $table->text('staff_notes')->nullable();

            $table->timestamps();

            // Drives the overlap check in BookingService and the availability sweep.
            $table->index(['user_id', 'blocked_starts_at', 'blocked_ends_at'], 'bookings_calendar_index');

            // Drives the reminder and hold-expiry sweeps.
            $table->index(['status', 'starts_at']);
            $table->index(['status', 'hold_expires_at']);

            $table->index(['customer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
