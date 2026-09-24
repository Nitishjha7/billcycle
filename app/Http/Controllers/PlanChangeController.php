<?php

namespace App\Http\Controllers;

use App\Billing\PlanChangeService;
use App\Models\Customer;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * See docs/UI_FLOW.md #4 -- the most important screen in the application.
 * The preview and the apply action call PlanChangeService with identical
 * inputs, so what the customer is shown and what actually happens can
 * never disagree.
 */
class PlanChangeController extends Controller
{
    public function __construct(
        private readonly PlanChangeService $planChanges,
    ) {}

    public function show(Customer $customer): View
    {
        $customer->load('subscriptions.plan');
        $subscription = $customer->subscriptions->sortByDesc('created_at')->first();
        $plans = Plan::where('is_active', true)->orderBy('price_paise')->get();

        return view('plan-change.show', [
            'customer' => $customer,
            'subscription' => $subscription,
            'plans' => $plans,
            'preview' => null,
            'selectedPlan' => null,
        ]);
    }

    public function preview(Request $request, Customer $customer): View
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);

        $customer->load('subscriptions.plan');
        $subscription = $customer->subscriptions->sortByDesc('created_at')->first();
        $newPlan = Plan::findOrFail($data['plan_id']);
        $plans = Plan::where('is_active', true)->orderBy('price_paise')->get();

        $preview = $this->planChanges->preview($subscription, $newPlan, CarbonImmutable::now());

        return view('plan-change.show', [
            'customer' => $customer,
            'subscription' => $subscription,
            'plans' => $plans,
            'preview' => $preview,
            'selectedPlan' => $newPlan,
        ]);
    }

    public function apply(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);

        $customer->load('subscriptions.plan');
        $subscription = $customer->subscriptions->sortByDesc('created_at')->first();
        $newPlan = Plan::findOrFail($data['plan_id']);

        $this->planChanges->apply($subscription, $newPlan, CarbonImmutable::now());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Plan changed to {$newPlan->name}.");
    }
}
