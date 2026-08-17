<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'timezone' => 'UTC',
            'notes' => null,
            'user_id' => null,
        ];
    }

    public function inTimezone(string $timezone): static
    {
        return $this->state(fn (array $attributes) => ['timezone' => $timezone]);
    }
}
