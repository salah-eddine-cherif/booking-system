<?php

use App\Enums\DepositType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // How long the customer actually occupies the staff member for.
            $table->unsignedSmallInteger('duration_minutes');

            // Padding around the appointment (travel, cleanup, notes). Blocked on
            // the calendar but not shown to the customer as part of their booking.
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);

            // Slots are offered on this cadence, e.g. every 15 minutes.
            $table->unsignedSmallInteger('slot_increment_minutes')->default(15);

            // Booking window guards.
            $table->unsignedInteger('min_notice_minutes')->default(120);
            $table->unsignedSmallInteger('max_advance_days')->default(60);

            // How many people may hold the same slot (1 = private appointment).
            $table->unsignedSmallInteger('capacity')->default(1);

            // Money is always stored in the smallest currency unit.
            $table->unsignedInteger('price_amount')->default(0);
            $table->string('currency', 3)->default('usd');

            $table->string('deposit_type')->default(DepositType::None->value);
            $table->unsignedInteger('deposit_value')->default(0);

            // How long a slot is held while the customer completes payment.
            $table->unsignedSmallInteger('payment_hold_minutes')->default(15);

            // Reminder lead time, in hours before the appointment starts.
            $table->unsignedSmallInteger('reminder_hours_before')->default(24);

            // Customers may not cancel inside this window.
            $table->unsignedInteger('cancellation_notice_minutes')->default(1440);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
