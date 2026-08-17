<?php

namespace Database\Factories;

use App\Models\AvailabilityException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityException>
 */
class AvailabilityExceptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->addWeek()->toDateString(),
            'is_available' => false,
            'start_time' => null,
            'end_time' => null,
            'reason' => 'Annual leave',
        ];
    }

    /** A whole day off. */
    public function dayOff(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'is_available' => false,
            'start_time' => null,
            'end_time' => null,
        ]);
    }

    /** A block carved out of an otherwise normal day. */
    public function blocking(string $date, string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'is_available' => false,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /** A one-off working window that replaces the weekly rules for that date. */
    public function extraHours(string $date, string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'is_available' => true,
            'start_time' => $start,
            'end_time' => $end,
            'reason' => 'One-off shift',
        ]);
    }
}
