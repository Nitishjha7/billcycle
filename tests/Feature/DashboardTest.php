<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

test('the dashboard api loads for a logged-in user with open invoices', function () {
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

    $response = $this->actingAs($user)->getJson('/api/dashboard');

    $response->assertOk();
    $response->assertJsonStructure([
        'mrr_paise',
        'counts',
        'activity',
        'recent_customers',
    ]);
});

test('a guest cannot reach the dashboard api', function () {
    $this->getJson('/api/dashboard')->assertUnauthorized();
});

test('the SPA shell is served for any frontend route', function () {
    $this->get('/')->assertOk();
    $this->get('/customers')->assertOk();
    $this->get('/customers/anything')->assertOk();
});
