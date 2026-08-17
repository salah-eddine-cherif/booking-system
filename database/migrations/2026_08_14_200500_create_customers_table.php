<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people doing the booking. They are Cashier-billable but usually never log
 * in, so they are kept separate from the staff `users` table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();

            // Used to render times back to the customer in their own zone.
            $table->string('timezone')->default('UTC');

            $table->text('notes')->nullable();

            // Optional link to a login, for customers who create an account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Cashier columns.
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();

            // One customer record per email address; bookings reuse it.
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
