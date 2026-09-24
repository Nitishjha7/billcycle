<?php

use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon as CarbonFacade;

afterEach(function () {
    CarbonFacade::setTestNow();
});

function gateway(): FakeGateway
{
    $gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $gateway);

    return $gateway;
}

function activeSubscription(int $pricePaise = 50000): Subscription
{
    $plan = Plan::factory()->create(['price_paise' => $pricePaise]);

    return Subscription::factory()->create([
        'plan_id' => $plan->id,
        'current_period_start' => CarbonFacade::now()->subMonth(),
        'current_period_end' => CarbonFacade::now()->subDay(),
    ]);
}

function runRetries(): void
{
    Artisan::call('dunning:retry');
}

function travelDays(int $days): void
{
    CarbonFacade::setTestNow(CarbonFacade::now()->addDays($days));
}

// --- The schedule -----------------------------------------------------

test('four failed attempts suspend the subscription', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    gateway()->alwaysFails('card_declined');

    $this->artisan('billing:run'); // attempt 1 fails
    expect($sub->fresh()->status)->toBe('past_due');

    travelDays(1);
    runRetries(); // attempt 2
    expect($sub->fresh()->status)->toBe('past_due');

    travelDays(3);
    runRetries(); // attempt 3
    expect($sub->fresh()->status)->toBe('past_due');

    travelDays(5);
    runRetries(); // attempt 4

    expect($sub->fresh()->status)->toBe('suspended');
    expect($sub->invoices()->first()->attempts()->count())->toBe(4);
});

// --- Recovery ------------------------------------------------------------

test('payment success on attempt two returns to active', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    $gw = gateway()->alwaysFails('card_declined');

    $this->artisan('billing:run'); // attempt 1 fails
    expect($sub->fresh()->status)->toBe('past_due');

    $gw->reset(); // attempt 2 succeeds
    travelDays(1);
    runRetries();

    expect($sub->fresh()->status)->toBe('active');
});

test('success clears the retry schedule', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    $gw = gateway()->alwaysFails();

    $this->artisan('billing:run'); // attempt 1 fails
    $invoice = $sub->invoices()->first();
    expect($invoice->attempts()->latest('attempted_at')->first()->next_retry_at)->not->toBeNull();

    $gw->reset();
    travelDays(1);
    runRetries(); // attempt 2 succeeds

    expect($invoice->fresh()->attempts()->latest('attempted_at')->first()->next_retry_at)->toBeNull();
});

test('success resets the attempt counter for a subsequent failure', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    $gw = gateway()->alwaysFails();

    $this->artisan('billing:run'); // attempt 1 fails
    $invoice = $sub->invoices()->first();
    expect($invoice->attempts()->count())->toBe(1);

    $gw->reset();
    travelDays(1);
    runRetries(); // attempt 2 succeeds
    expect($sub->fresh()->status)->toBe('active');

    // A fresh invoice's attempt numbering starts at 1 again -- the counter
    // is scoped per invoice, not carried across invoices.
    travelDays(30);
    $this->artisan('billing:run');
    $secondInvoice = $sub->invoices()->latest('issued_at')->first();
    expect($secondInvoice->id)->not->toBe($invoice->id);
    expect($secondInvoice->attempts()->first()->attempt_number)->toBe(1);
});

test('payment after suspension reactivates', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    $gw = gateway()->alwaysFails();

    $this->artisan('billing:run');
    travelDays(1);
    runRetries();
    travelDays(3);
    runRetries();
    travelDays(5);
    runRetries();

    expect($sub->fresh()->status)->toBe('suspended');

    $gw->reset();
    travelDays(1);
    runRetries();

    expect($sub->fresh()->status)->toBe('active');
});

test('reactivation restarts the period from the payment date', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    $gw = gateway()->alwaysFails();

    $this->artisan('billing:run');
    travelDays(1);
    runRetries();
    travelDays(3);
    runRetries();
    travelDays(5);
    runRetries();
    expect($sub->fresh()->status)->toBe('suspended');

    $gw->reset();
    travelDays(6); // suspended for 6 days total, then pays
    $paymentDate = CarbonFacade::now();
    runRetries();

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->current_period_start->toDateString())->toBe($paymentDate->toDateString());
});

// --- The state machine itself --------------------------------------------

test('illegal transition throws', function () {
    $sub = Subscription::factory()->suspended()->create();

    expect(fn () => App\Billing\SubscriptionStateMachine::transition($sub, 'past_due'))
        ->toThrow(LogicException::class);
});

test('every transition is recorded as a payment attempt with its outcome', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    gateway()->alwaysFails('card_declined');

    $this->artisan('billing:run');
    travelDays(1);
    runRetries();

    $attempts = $sub->invoices()->first()->attempts()->orderBy('attempt_number')->get();

    expect($attempts)->toHaveCount(2);
    expect($attempts[0]->attempt_number)->toBe(1);
    expect($attempts[0]->failure_code)->toBe('card_declined');
    expect($attempts[1]->attempt_number)->toBe(2);
});

test('retry intervals are one three and five days', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    gateway()->alwaysFails();

    $this->artisan('billing:run');
    $invoice = $sub->invoices()->first();
    $attempt1 = $invoice->attempts()->where('attempt_number', 1)->sole();
    expect((int) $attempt1->attempted_at->diffInDays($attempt1->next_retry_at))->toBe(1);

    travelDays(1);
    runRetries();
    $attempt2 = $invoice->attempts()->where('attempt_number', 2)->sole();
    expect((int) $attempt2->attempted_at->diffInDays($attempt2->next_retry_at))->toBe(3);

    travelDays(3);
    runRetries();
    $attempt3 = $invoice->attempts()->where('attempt_number', 3)->sole();
    expect((int) $attempt3->attempted_at->diffInDays($attempt3->next_retry_at))->toBe(5);

    travelDays(5);
    runRetries();
    $attempt4 = $invoice->attempts()->where('attempt_number', 4)->sole();
    expect($attempt4->next_retry_at)->toBeNull();
});

// --- Failure codes ---------------------------------------------------------

test('card declined and insufficient funds follow the same schedule', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    gateway()->alwaysFails('insufficient_funds');

    $this->artisan('billing:run');
    $attempt = $sub->invoices()->first()->attempts()->sole();

    expect($attempt->failure_code)->toBe('insufficient_funds');
    expect($attempt->next_retry_at)->not->toBeNull();
    expect($sub->fresh()->status)->toBe('past_due');
});

test('the failure code is stored on the attempt', function () {
    CarbonFacade::setTestNow(CarbonImmutable::parse('2026-09-01'));
    $sub = activeSubscription();
    gateway()->alwaysFails('insufficient_funds');

    $this->artisan('billing:run');

    expect($sub->invoices()->first()->attempts()->sole()->failure_code)->toBe('insufficient_funds');
});
