<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfDay();
        $amount = fake()->randomElement([50000, 120000, 300000]);

        return [
            'subscription_id' => Subscription::factory(),
            'number' => 'INV-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'period_start' => $start,
            'period_end' => $start->copy()->addMonth(),
            'subtotal_paise' => $amount,
            'total_paise' => $amount,
            'status' => 'open',
            'issued_at' => $start,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'void',
        ]);
    }
}
