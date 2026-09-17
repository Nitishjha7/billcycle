<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanChange>
 */
class PlanChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'from_plan_id' => Plan::factory(),
            'to_plan_id' => Plan::factory(),
            'changed_at' => now(),
            'credit_paise' => 25000,
            'charge_paise' => 60000,
            'net_paise' => 35000,
        ];
    }
}
