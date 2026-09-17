<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => 'Subscription',
            'amount_paise' => fake()->randomElement([50000, 120000, 300000]),
            'type' => 'subscription',
        ];
    }

    public function prorationCredit(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => 'Unused time on previous plan',
            'amount_paise' => -abs($attributes['amount_paise'] ?? 10000),
            'type' => 'proration_credit',
        ]);
    }

    public function prorationCharge(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => 'Remaining time on new plan',
            'type' => 'proration_charge',
        ]);
    }
}
