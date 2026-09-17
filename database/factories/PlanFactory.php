<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Basic', 'Pro', 'Enterprise']),
            'price_paise' => fake()->randomElement([50000, 120000, 300000]),
            'interval' => 'monthly',
            'trial_days' => 0,
            'is_active' => true,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'yearly',
        ]);
    }

    public function withTrial(int $days = 14): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_days' => $days,
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_paise' => 0,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
