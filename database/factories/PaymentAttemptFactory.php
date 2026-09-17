<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
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
            'attempt_number' => 1,
            'failure_code' => null,
            'attempted_at' => now(),
            'next_retry_at' => null,
        ];
    }

    public function failed(string $code = 'card_declined'): static
    {
        return $this->state(fn (array $attributes) => [
            'failure_code' => $code,
        ]);
    }
}
