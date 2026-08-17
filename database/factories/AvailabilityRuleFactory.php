<?php

namespace Database\Factories;

use App\Models\AvailabilityRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityRule>
 */
class AvailabilityRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'effective_from' => null,
            'effective_until' => null,
        ];
    }

    public function on(int $dayOfWeek, string $start = '09:00:00', string $end = '17:00:00'): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}
