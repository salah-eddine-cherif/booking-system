<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which staff members are qualified to deliver which service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Optional per-staff price override, in minor units.
            $table->unsignedInteger('price_amount')->nullable();

            $table->timestamps();

            $table->unique(['service_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_user');
    }
};
