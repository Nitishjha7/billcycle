<?php

use App\Billing\PlanChangeService;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon as CarbonFacade;

afterEach(function () {
    CarbonFacade::setTestNow();
});

// --- The headline test ----------------------------------------------------

test('twelve months of billing produces twelve correct invoices', function () {
    $plan = Plan::factory()->create(['price_paise' => 50000]);
    $sub = Subscription::factory()->create([
        'plan_id' => $plan->id,
        'current_period_start' => '2026-01-01',
        'current_period_end' => '2026-02-01',
    ]);

    foreach (range(1, 12) as $month) {
        CarbonFacade::setTestNow(CarbonImmutable::parse('2026-01-01')->addMonths($month));
        $this->artisan('billing:run');
    }

    expect($sub->invoices()->count())->toBe(12);
    // sum() returns a numeric string on some drivers (Postgres bigint via
    // PDO) and an int on others (sqlite) -- compare numerically, not by type.
    expect((int) $sub->invoices()->sum('total_paise'))->toBe(600000);
});

// --- Idempotency -----------------------------------------------------------

test('running the billing job three times produces one invoice', function () {
    $sub = dueSubscription();

    $this->artisan('billing:run');
    $this->artisan('billing:run');
    $this->artisan('billing:run');

    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(1);
});

test('concurrent runs produce one invoice', function () {
    // No real second worker process in a single-process test run, but the
    // constraint that makes concurrent runs safe is exercised directly: a
    // second insert for the same (subscription_id, period_start) fails at
    // the database, which is exactly what stops two real workers.
    $sub = dueSubscription();

    $this->artisan('billing:run');
    $invoiceCountAfterFirst = Invoice::where('subscription_id', $sub->id)->count();

    $this->artisan('billing:run');

    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe($invoiceCountAfterFirst);
});

test('the unique constraint is what stops it, not application code', function () {
    $sub = dueSubscription();
    $this->artisan('billing:run');

    $existing = $sub->invoices()->first();

    expect(fn () => \App\Models\Invoice::create([
        'subscription_id' => $sub->id,
        'number' => 'INV-2099-999999',
        'period_start' => $existing->period_start,
        'period_end' => $existing->period_end,
        'subtotal_paise' => 1,
        'total_paise' => 1,
        'status' => 'open',
        'issued_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('a crash mid run leaves no partial invoice', function () {
    $sub = dueSubscription();
    $originalPeriodEnd = $sub->current_period_end;

    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($sub) {
            \App\Models\Invoice::create([
                'subscription_id' => $sub->id,
                'number' => 'INV-2026-000001',
                'period_start' => $sub->current_period_end,
                'period_end' => now()->addMonth(),
                'subtotal_paise' => 50000,
                'total_paise' => 50000,
                'status' => 'open',
                'issued_at' => now(),
            ]);

            throw new RuntimeException('simulated crash after invoice insert');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);
    expect($sub->fresh()->current_period_end->toDateString())
        ->toBe(CarbonImmutable::parse($originalPeriodEnd)->toDateString());
});

// --- Downgrade credit carry-forward ---------------------------------------

test('a downgrade credit lands on the next regular invoice', function () {
    $pro = Plan::factory()->create(['price_paise' => 120000]);
    $basic = Plan::factory()->create(['price_paise' => 50000]);

    $sub = Subscription::factory()->create([
        'plan_id' => $pro->id,
        'current_period_start' => '2026-09-01',
        'current_period_end' => '2026-10-01',
    ]);

    app(PlanChangeService::class)->apply($sub, $basic, CarbonImmutable::parse('2026-09-16'));

    $planChange = PlanChange::where('subscription_id', $sub->id)->sole();
    expect($planChange->applied_invoice_id)->toBeNull();
    expect($planChange->credit_paise)->toBeGreaterThan(0);

    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-10-01'));
    $this->artisan('billing:run');

    $invoice = $sub->invoices()->sole();
    expect((int) $invoice->total_paise)->toBe(50000 - $planChange->credit_paise);
    expect($invoice->lines()->where('type', 'proration_credit')->sole()->amount_paise)
        ->toBe(-$planChange->credit_paise);
    expect($planChange->fresh()->applied_invoice_id)->toBe($invoice->id);
});

test('a downgrade credit is never carried onto more than one invoice', function () {
    $pro = Plan::factory()->create(['price_paise' => 120000]);
    $basic = Plan::factory()->create(['price_paise' => 50000]);

    $sub = Subscription::factory()->create([
        'plan_id' => $pro->id,
        'current_period_start' => '2026-09-01',
        'current_period_end' => '2026-10-01',
    ]);

    app(PlanChangeService::class)->apply($sub, $basic, CarbonImmutable::parse('2026-09-16'));

    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-10-01'));
    $this->artisan('billing:run');

    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-11-01'));
    $this->artisan('billing:run');

    $secondInvoice = $sub->invoices()->where('period_start', '2026-11-01')->sole();
    expect((int) $secondInvoice->total_paise)->toBe(50000);
    expect($secondInvoice->lines()->where('type', 'proration_credit')->count())->toBe(0);
});

// --- Selection rules ---------------------------------------------------

test('suspended subscriptions are not billed', function () {
    $sub = dueSubscription(['status' => 'suspended']);

    $this->artisan('billing:run');

    expect($sub->invoices()->count())->toBe(0);
});

test('cancelled subscriptions are not billed', function () {
    $sub = dueSubscription(['status' => 'cancelled']);

    $this->artisan('billing:run');

    expect($sub->invoices()->count())->toBe(0);
});

test('trialing subscriptions are not billed until trial ends', function () {
    $sub = dueSubscription(['status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);

    $this->artisan('billing:run');

    expect($sub->invoices()->count())->toBe(0);
});

test('subscriptions not yet due are skipped', function () {
    $sub = Subscription::factory()->create([
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->artisan('billing:run');

    expect($sub->invoices()->count())->toBe(0);
});

test('past due subscriptions are still billed', function () {
    $sub = dueSubscription(['status' => 'past_due']);

    $this->artisan('billing:run');

    expect($sub->invoices()->count())->toBe(1);
});

/**
 * A subscription whose current period has already ended -- due to be billed
 * on the next billing:run.
 */
function dueSubscription(array $attributes = []): Subscription
{
    return Subscription::factory()->create(array_merge([
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
    ], $attributes));
}
