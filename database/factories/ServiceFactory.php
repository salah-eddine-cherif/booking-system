<?php

namespace Database\Factories;

use App\Enums\DepositType;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Initial Consultation', 'Follow-up Session', 'Deep Tissue Massage',
            'Colour and Cut', 'Tax Review', 'Physiotherapy Assessment',
            'Personal Training', 'Maths Tutoring', 'Dental Check-up',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->paragraph(),
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'slot_increment_minutes' => 30,
            'min_notice_minutes' => 120,
            'max_advance_days' => 60,
            'capacity' => 1,
            'price_amount' => 9000,
            'currency' => 'usd',
            'deposit_type' => DepositType::None,
            'deposit_value' => 0,
            'payment_hold_minutes' => 15,
            'reminder_hours_before' => 24,
            'cancellation_notice_minutes' => 1440,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function lasting(int $minutes): static
    {
        return $this->state(fn (array $attributes) => ['duration_minutes' => $minutes]);
    }

    public function withBuffers(int $before, int $after): static
    {
        return $this->state(fn (array $attributes) => [
            'buffer_before_minutes' => $before,
            'buffer_after_minutes' => $after,
        ]);
    }

    public function everyMinutes(int $increment): static
    {
        return $this->state(fn (array $attributes) => ['slot_increment_minutes' => $increment]);
    }

    /** A group class: several customers may hold the same start time. */
    public function groupOf(int $capacity): static
    {
        return $this->state(fn (array $attributes) => ['capacity' => $capacity]);
    }

    public function withFixedDeposit(int $minorUnits): static
    {
        return $this->state(fn (array $attributes) => [
            'deposit_type' => DepositType::Fixed,
            'deposit_value' => $minorUnits,
        ]);
    }

    public function withPercentageDeposit(int $percent): static
    {
        return $this->state(fn (array $attributes) => [
            'deposit_type' => DepositType::Percentage,
            'deposit_value' => $percent,
        ]);
    }

    /** No lead time or horizon limits — keeps time-travel tests readable. */
    public function bookableAnytime(): static
    {
        return $this->state(fn (array $attributes) => [
            'min_notice_minutes' => 0,
            'max_advance_days' => 730,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
