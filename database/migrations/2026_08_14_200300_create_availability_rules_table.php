<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recurring weekly shape of a staff member's week.
 *
 * Times are wall-clock local times in the staff member's own timezone. They are
 * deliberately not stored as UTC: "Ana works 09:00-17:00 on Mondays" must stay
 * true on both sides of a daylight-saving transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 0 = Sunday .. 6 = Saturday, matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');

            $table->time('start_time');
            $table->time('end_time');

            // Optional validity window, for onboarding or contract end dates.
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_rules');
    }
};
