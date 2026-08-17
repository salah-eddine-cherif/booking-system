<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Staff,
            'timezone' => 'UTC',
            'title' => fake()->jobTitle(),
            'bio' => fake()->sentence(12),
            'is_bookable' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * An admin who manages the calendar but does not appear on it.
     */
    public function notBookable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bookable' => false,
        ]);
    }

    public function inTimezone(string $timezone): static
    {
        return $this->state(fn (array $attributes) => [
            'timezone' => $timezone,
        ]);
    }

    /**
     * Give the staff member a plain Mon-Fri, 09:00-17:00 week in their own zone.
     */
    public function workingWeekdays(string $start = '09:00:00', string $end = '17:00:00'): static
    {
        return $this->afterCreating(function (User $user) use ($start, $end) {
            foreach ([1, 2, 3, 4, 5] as $dayOfWeek) {
                $user->availabilityRules()->create([
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $start,
                    'end_time' => $end,
                ]);
            }
        });
    }
}
