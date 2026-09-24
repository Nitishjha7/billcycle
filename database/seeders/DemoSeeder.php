<?php

namespace Database\Seeders;

use App\Billing\BillingRunner;
use App\Billing\DunningRetryRunner;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Billing\PlanChangeService;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * php artisan db:seed --class=DemoSeeder
 * SEED_PROFILE=messy php artisan db:seed --class=DemoSeeder
 *
 * The single most important piece of demo infrastructure in the project --
 * see docs/UI_FLOW.md's seed data specification. Deliberately drives the
 * real BillingRunner / PlanChangeService / DunningRetryRunner forward
 * through eight simulated months rather than fabricating rows directly:
 * that is what makes invoice numbers genuinely sequential and gapless, and
 * every amount an actual system output rather than a plausible-looking
 * guess.
 */
class DemoSeeder extends Seeder
{
    private BillingRunner $billing;

    private DunningRetryRunner $dunningRetry;

    private PlanChangeService $planChanges;

    private FakeGateway $gateway;

    public function run(): void
    {
        // db:seed does not forward arbitrary CLI options to the seeder
        // class (only --class, --database, --force are recognised), so
        // the messy profile is selected via an env var instead:
        // SEED_PROFILE=messy php artisan db:seed --class=DemoSeeder
        $messy = env('SEED_PROFILE') === 'messy';

        $this->gateway = new FakeGateway;
        app()->instance(PaymentGateway::class, $this->gateway);
        $this->billing = app(BillingRunner::class);
        $this->dunningRetry = app(DunningRetryRunner::class);
        $this->planChanges = app(PlanChangeService::class);

        [$basic, $pro, $enterprise] = $this->plans();

        $start = CarbonImmutable::parse('2026-01-15')->startOfDay();
        Carbon::setTestNow($start);

        $customerCount = 50;
        $unhealthyShare = $messy ? 0.30 : 0.16;

        $customers = Customer::factory()->count($customerCount)->create();
        $subscriptions = $customers->map(function (Customer $customer) use ($basic, $pro, $enterprise, $start) {
            $plan = fake()->randomElement([$basic, $basic, $pro, $pro, $enterprise]);

            return Subscription::factory()->create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'current_period_start' => $start,
                'current_period_end' => $start->addMonth(),
            ]);
        });

        // A handful of customers upgrade or downgrade partway through the
        // history, leaving real proration lines in old invoices.
        $upgraders = $subscriptions->random(6);

        // One customer deep in dunning: 3 failed attempts, suspended.
        $deepInDunning = $subscriptions->except($upgraders->modelKeys())->random();

        // One customer who recovered: failed twice, then paid.
        $excludedForRecovered = [...$upgraders->modelKeys(), $deepInDunning->id];
        $recovered = $subscriptions->except($excludedForRecovered)->random();

        // One customer left mid-retry (past_due) at the end of the seeded
        // history -- otherwise every unhealthy account resolves all the way
        // to either suspended or active by month 8, and past_due (a real
        // subscription status) never appears in the seeded data at all.
        $excludedForStuck = [...$excludedForRecovered, $recovered->id];
        $stuckInRetry = $subscriptions->except($excludedForStuck)->random();

        $reservedIds = [$deepInDunning->id, $recovered->id, $stuckInRetry->id];
        $unhealthyPool = $subscriptions
            ->reject(fn (Subscription $s) => in_array($s->id, $reservedIds, true))
            ->random((int) round($customerCount * $unhealthyShare));

        // deepInDunning and recovered fail on month 6's invoice specifically
        // -- their own dedicated drive* methods walk the schedule from
        // there, independent of the general unhealthy pool.
        $reservedFailMonth = 6;

        foreach (range(1, 8) as $month) {
            $preBillingMoment = $start->addMonths($month)->subDay();
            Carbon::setTestNow($preBillingMoment);

            // Plan changes happen mid-cycle, a few days before the month's
            // billing run, so they leave real proration in the invoice
            // history rather than always landing on the boundary.
            if ($month === 3 || $month === 5) {
                foreach ($upgraders->take(3) as $subscription) {
                    $this->attemptPlanChange($subscription, [$basic, $pro, $enterprise]);
                }
            }

            Carbon::setTestNow($start->addMonths($month));

            $unhealthyThisMonth = $month >= 2
                ? $unhealthyPool
                    ->slice(0, (int) ceil($unhealthyPool->count() / 2))
                    ->filter(fn (Subscription $s) => in_array($s->fresh()->status, ['active', 'past_due'], true))
                : collect();

            $failingThisMonth = $unhealthyThisMonth->pluck('id')->all();

            if ($month === $reservedFailMonth) {
                $failingThisMonth = [...$failingThisMonth, $deepInDunning->id, $recovered->id];
            }

            if ($month === 8) {
                $failingThisMonth[] = $stuckInRetry->id;
            }

            // The healthy majority is billed as a normal batch, gateway
            // succeeding.
            $this->gateway->reset();
            $this->billing->run(
                CarbonImmutable::instance(Carbon::now()),
                excludeSubscriptionIds: $failingThisMonth,
            );

            // Each failing subscription is billed one at a time, with the
            // gateway forced to fail *before* billing:run dispatches that
            // subscription's first payment attempt -- BillingRunner
            // dispatches the attempt itself right after the invoice
            // commits, so failure has to be armed ahead of the call.
            foreach ($failingThisMonth as $subscriptionId) {
                $this->gateway->alwaysFails(fake()->randomElement(['card_declined', 'insufficient_funds']));
                $this->billing->run(
                    CarbonImmutable::instance(Carbon::now()),
                    onlySubscriptionId: $subscriptionId,
                );
                $this->gateway->reset();
            }

            // The last month's failures are left mid-schedule (past_due,
            // one retry still pending) rather than walked to a terminal
            // state -- otherwise every unhealthy account resolves to either
            // suspended or active by the end, and past_due never appears in
            // the seeded data at all.
            $isLastMonth = $month === 8;

            foreach ($unhealthyThisMonth as $subscription) {
                $this->partiallyRetry($subscription, walkToTerminal: ! $isLastMonth);
            }
        }

        $this->driveDeepDunning($deepInDunning);
        $this->driveRecovery($recovered);

        // A couple of trials and a couple of cancellations round out all
        // five statuses being present, per docs/UI_FLOW.md.
        $subscriptions->except([$deepInDunning->id, $recovered->id])
            ->random(3)
            ->each(fn (Subscription $s) => $s->update([
                'status' => 'trialing',
                'trial_ends_at' => Carbon::now()->addDays(7),
            ]));

        $subscriptions->except([$deepInDunning->id, $recovered->id])
            ->reject(fn (Subscription $s) => $s->fresh()->status === 'trialing')
            ->random(2)
            ->each(fn (Subscription $s) => $s->update([
                'status' => 'cancelled',
                'cancel_at_period_end' => true,
            ]));

        Carbon::setTestNow();

        $this->command?->info('Seeded 50 customers across 8 simulated months of billing history.');
    }

    /**
     * @return array{0: Plan, 1: Plan, 2: Plan}
     */
    private function plans(): array
    {
        return [
            Plan::factory()->create(['name' => 'Basic', 'price_paise' => 50000, 'interval' => 'monthly']),
            Plan::factory()->create(['name' => 'Pro', 'price_paise' => 120000, 'interval' => 'monthly']),
            Plan::factory()->create(['name' => 'Enterprise', 'price_paise' => 300000, 'interval' => 'monthly']),
        ];
    }

    private function attemptPlanChange(Subscription $subscription, array $plans): void
    {
        $subscription->refresh()->loadMissing('plan');

        if (in_array($subscription->status, ['cancelled', 'suspended', 'trialing'], true)) {
            return;
        }

        $target = collect($plans)->first(fn (Plan $p) => $p->id !== $subscription->plan_id);

        try {
            $this->planChanges->apply($subscription, $target, CarbonImmutable::instance(Carbon::now()->subDays(random_int(3, 10))));
        } catch (\Throwable) {
            // A same-plan or otherwise invalid change in this random walk
            // is skipped, not fatal to the seed run.
        }
    }

    /**
     * Walks 1-2 more steps of the retry schedule for a subscription whose
     * invoice already failed its first attempt this month -- some retry a
     * little, some go all the way, so unhealthy accounts don't all look
     * identical.
     */
    private function partiallyRetry(Subscription $subscription, bool $walkToTerminal = true): void
    {
        $subscription->refresh();
        $invoice = $subscription->invoices()->latest('issued_at')->first();

        if ($invoice === null || $invoice->status !== 'open') {
            return;
        }

        // Left mid-schedule (past_due, one retry still pending): stop after
        // a single step so the subscription is left there rather than
        // walked all the way to suspended or active.
        $steps = $walkToTerminal ? random_int(1, 2) : 1;

        foreach (range(1, $steps) as $i) {
            $attempt = $invoice->fresh()->attempts()->latest('attempted_at')->first();

            if ($attempt === null || $attempt->next_retry_at === null) {
                break;
            }

            Carbon::setTestNow($attempt->next_retry_at);
            $this->gateway->alwaysFails(fake()->randomElement(['card_declined', 'insufficient_funds']));
            $this->dunningRetry->run(CarbonImmutable::instance(Carbon::now()));
            $this->gateway->reset();
        }
    }

    private function driveDeepDunning(Subscription $subscription): void
    {
        $subscription->refresh();
        $invoice = $subscription->invoices()->where('status', 'open')->latest('issued_at')->first();

        if ($invoice === null) {
            return;
        }

        foreach (range(1, 3) as $i) {
            $attempt = $invoice->fresh()->attempts()->latest('attempted_at')->first();

            if ($attempt === null || $attempt->next_retry_at === null) {
                break;
            }

            Carbon::setTestNow($attempt->next_retry_at);
            $this->gateway->alwaysFails('card_declined');
            $this->dunningRetry->run(CarbonImmutable::instance(Carbon::now()));
        }

        $this->gateway->reset();
    }

    private function driveRecovery(Subscription $subscription): void
    {
        $subscription->refresh();
        $invoice = $subscription->invoices()->where('status', 'open')->latest('issued_at')->first();

        if ($invoice === null) {
            return;
        }

        foreach (range(1, 2) as $i) {
            $attempt = $invoice->fresh()->attempts()->latest('attempted_at')->first();

            if ($attempt === null || $attempt->next_retry_at === null) {
                break;
            }

            Carbon::setTestNow($attempt->next_retry_at);
            $this->gateway->alwaysFails('card_declined');
            $this->dunningRetry->run(CarbonImmutable::instance(Carbon::now()));
        }

        $attempt = $invoice->fresh()->attempts()->latest('attempted_at')->first();

        if ($attempt?->next_retry_at) {
            Carbon::setTestNow($attempt->next_retry_at);
            $this->gateway->reset(); // this attempt succeeds
            $this->dunningRetry->run(CarbonImmutable::instance(Carbon::now()));
        }

        $this->gateway->reset();
    }
}
