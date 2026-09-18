<?php

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;

test('the app boots, migrations run, and a subscription can be created end to end', function () {
    $customer = Customer::factory()->create();
    $plan = Plan::factory()->create(['price_paise' => 50000]);

    $subscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
    ]);

    expect($subscription->customer->id)->toBe($customer->id);
    expect($subscription->plan->price_paise)->toBe(50000);
    expect($subscription->status)->toBe('active');
});
