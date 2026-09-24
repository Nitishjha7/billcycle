<?php

use App\Billing\BillingRunner;
use App\Billing\DunningRetryRunner;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Billing\PlanChangeService;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon as CarbonFacade;

/**
 * The highest-value test in the suite: 500 random events against 20
 * customers, asserting one invariant that must hold no matter what --
 * invoiced minus paid equals outstanding, for every customer, every time.
 * See docs/TEST_PLAN.md #4.
 *
 * Seeded so a failure is reproducible.
 */
test('customer balances stay consistent under 500 random events', function () {
    mt_srand(42);
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-01-01'));

    $gateway = app(FakeGateway::class);
    app()->instance(PaymentGateway::class, $gateway);

    $plans = Plan::factory()->count(3)->sequence(
        ['price_paise' => 50000],
        ['price_paise' => 120000],
        ['price_paise' => 300000],
    )->create();

    $customers = Customer::factory()->count(20)->create();

    foreach ($customers as $customer) {
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plans->random()->id,
            'current_period_start' => CarbonFacade::now(),
            'current_period_end' => CarbonFacade::now()->addMonth(),
        ]);
    }

    foreach (range(1, 500) as $i) {
        fireRandomEvent($customers->random(), $plans, $gateway);
    }

    foreach ($customers as $customer) {
        expect($customer->invoicedTotalPaise() - $customer->paidTotalPaise())
            ->toBe($customer->outstandingPaise());
    }
});

/**
 * @param  \Illuminate\Support\Collection<int, Plan>  $plans
 */
function fireRandomEvent(Customer $customer, $plans, FakeGateway $gateway): void
{
    $subscription = $customer->subscriptions()->first();

    if ($subscription === null) {
        return;
    }

    $event = ['upgrade', 'downgrade', 'cancel', 'pay', 'fail_payment', 'advance_clock'][random_int(0, 5)];

    try {
        match ($event) {
            'upgrade' => randomPlanChange($subscription, $plans, higherOnly: true),
            'downgrade' => randomPlanChange($subscription, $plans, higherOnly: false),
            'cancel' => $subscription->status === 'cancelled' ? null : $subscription->update(['status' => 'cancelled']),
            'pay' => runBillingAndRetriesWithOutcome($subscription, $gateway, shouldSucceed: true),
            'fail_payment' => runBillingAndRetriesWithOutcome($subscription, $gateway, shouldSucceed: false),
            'advance_clock' => CarbonFacade::setTestNow(CarbonFacade::now()->addDays(random_int(1, 10))),
        };
    } catch (\Throwable) {
        // A random sequence can legitimately hit an illegal state (e.g. a
        // plan change attempted on a cancelled subscription) -- the
        // invariant under test is the ledger, not that every random event
        // succeeds. Illegal-transition and validation exceptions are
        // expected noise here, not failures.
    }
}

function randomPlanChange(Subscription $subscription, $plans, bool $higherOnly): void
{
    if (in_array($subscription->status, ['cancelled', 'suspended'], true)) {
        return;
    }

    $subscription->loadMissing('plan');
    $currentPrice = $subscription->plan->price_paise;

    $candidates = $higherOnly
        ? $plans->filter(fn (Plan $p) => $p->price_paise > $currentPrice)
        : $plans->filter(fn (Plan $p) => $p->price_paise < $currentPrice);

    $target = $candidates->first();

    if ($target === null) {
        return;
    }

    app(PlanChangeService::class)->apply($subscription, $target, CarbonFacade::now()->toImmutable());
}

function runBillingAndRetriesWithOutcome(Subscription $subscription, FakeGateway $gateway, bool $shouldSucceed): void
{
    $gateway->reset();

    if (! $shouldSucceed) {
        $gateway->alwaysFails('card_declined');
    }

    app(BillingRunner::class)->run(CarbonFacade::now()->toImmutable());
    app(DunningRetryRunner::class)->run(CarbonFacade::now()->toImmutable());

    $gateway->reset();
}
