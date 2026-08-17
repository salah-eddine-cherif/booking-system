<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date-specific overrides layered on top of the weekly rules.
 *
 * Resolution order for a given local date:
 *   1. If any is_available = true row exists for the date, those windows REPLACE
 *      the weekly rules entirely (e.g. "working a one-off Saturday, 10-14").
 *   2. Every is_available = false row is then subtracted from whatever remains.
 *      A row with null times means the whole day is blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Local date in the staff member's timezone.
            $table->date('date');

            $table->boolean('is_available')->default(false);

            // Null start/end means "the entire day".
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_exceptions');
    }
};
