<?php

namespace App\Http\Controllers\Api;

use App\Billing\PlanChangeService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * See docs/UI_FLOW.md #4 -- the most important screen in the application.
 * preview() and apply() call PlanChangeService with identical inputs, so
 * what the customer is shown and what actually happens can never disagree.
 */
class PlanChangeController extends Controller
{
    public function __construct(
        private readonly PlanChangeService $planChanges,
    ) {}

    public function plans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->orderBy('price_paise')->get();

        return response()->json([
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price_paise' => $plan->price_paise,
                'interval' => $plan->interval,
            ]),
        ]);
    }

    public function preview(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);

        $customer->load('subscriptions.plan');
        $subscription = $customer->subscriptions->sortByDesc('created_at')->first();
        $newPlan = Plan::findOrFail($data['plan_id']);

        $preview = $this->planChanges->preview($subscription, $newPlan, CarbonImmutable::now());

        return response()->json([
            'credit_paise' => $preview->creditPaise,
            'charge_paise' => $preview->chargePaise,
            'net_paise' => $preview->netPaise,
            'current_plan_name' => $subscription->plan->name,
            'new_plan_name' => $newPlan->name,
            'cycle_start' => $subscription->current_period_start->toDateString(),
            'cycle_end' => $subscription->current_period_end->toDateString(),
        ]);
    }

    public function apply(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);

        $customer->load('subscriptions.plan');
        $subscription = $customer->subscriptions->sortByDesc('created_at')->first();
        $newPlan = Plan::findOrFail($data['plan_id']);

        $this->planChanges->apply($subscription, $newPlan, CarbonImmutable::now());

        return response()->json(['message' => "Plan changed to {$newPlan->name}."]);
    }
}
