<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

test('the dashboard loads for a logged-in user with open invoices', function () {
    $user = User::factory()->create();

    $customer = Customer::factory()->create();
    $plan = Plan::factory()->create(['price_paise' => 50000]);
    $subscription = Subscription::factory()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
    ]);
    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => 'open',
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $response->assertSee('Dashboard');
});

test('a guest is redirected to login', function () {
    $this->get('/')->assertRedirect('/login');
});
