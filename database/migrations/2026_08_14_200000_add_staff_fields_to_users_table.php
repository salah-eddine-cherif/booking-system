<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users are the staff side of the system: the people whose calendars get booked.
 * Customers live in their own table because they usually never log in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::Staff->value)->after('email');

            // The staff member's own timezone. Availability rules are written as
            // wall-clock times and are interpreted against this zone.
            $table->string('timezone')->default('UTC')->after('role');

            $table->string('title')->nullable()->after('timezone');
            $table->text('bio')->nullable()->after('title');

            // Admins who only manage the schedule are not themselves bookable.
            $table->boolean('is_bookable')->default(true)->after('bio');

            $table->index(['is_bookable', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_bookable', 'role']);
            $table->dropColumn(['role', 'timezone', 'title', 'bio', 'is_bookable']);
        });
    }
};
