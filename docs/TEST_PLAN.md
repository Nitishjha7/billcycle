# Test Plan

Every test to write, grouped by what it protects. Target: **~70 tests**, Pest.

The behaviour being pinned is specified in [TECHNICAL_SPEC.md](TECHNICAL_SPEC.md).

---

## The principle

The three tests that matter most are not unit tests of individual methods. They
are:

1. **A full year of billing**, run in milliseconds via clock control.
2. **500 random events**, asserting an invariant that must hold no matter what.
3. **The same job run three times**, asserting nothing happened the second and
   third time.

Those three describe the system. The rest pin the edges.

---

## 1. Proration — ~30 tests

`tests/Unit/ProrationCalculatorTest.php`

No database. No clock. Pure input to output. These run in milliseconds and are
the cheapest tests in the project to write, which is the entire point of making
the calculator a pure function.

### Basic arithmetic

```php
test('upgrade mid cycle credits unused time and charges the new plan', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(50000),     // Rs 500
        newPlan: plan(120000),    // Rs 1200
        changeDate: date('2026-09-15'),
        cycleStart: date('2026-09-01'),
        cycleEnd:   date('2026-10-01'),
    );

    expect($r->creditPaise)->toBe(25000);   // 15/30 of Rs 500
    expect($r->chargePaise)->toBe(60000);   // 15/30 of Rs 1200
    expect($r->netPaise)->toBe(35000);      // Rs 350 due now
});
```

- `test_downgrade_produces_a_negative_net`
- `test_net_is_charge_minus_credit_always`

### Cycle length

- `test_february_uses_28_days_not_30` — the daily rate must come from the real cycle
- `test_march_uses_31_days`
- `test_leap_year_february_uses_29_days`
- `test_yearly_plan_prorates_over_365_days`

### Boundaries

- `test_change_on_cycle_start_day_credits_the_whole_period`
- `test_change_on_cycle_end_day_produces_zero_and_zero` — and must not divide by zero
- `test_change_one_day_before_end_credits_one_day`

### Rounding

- `test_remainder_favours_the_customer` — credit rounds up, charge rounds down
- `test_rounding_never_loses_more_than_one_paisa`
- `test_no_calculation_ever_returns_a_float` — assert the return type is `int`

### Plan shapes

- `test_upgrade_during_trial_prorates_nothing`
- `test_same_plan_change_is_rejected`
- `test_free_plan_to_paid_plan_charges_without_credit`
- `test_paid_plan_to_free_plan_credits_without_charge`

### Known failing test

```php
test('two plan changes on the same day do not double credit', function () {
    // Basic -> Pro -> Enterprise, all on the 15th.
    // The second change must prorate from the 15th, not re-credit
    // the full remaining period of a plan already credited once.
})->todo('Known limitation - see INTERVIEW_NOTES.md section on limitations');
```

**This test is written and left failing on purpose.** It is named in the README
and in [INTERVIEW_NOTES.md](INTERVIEW_NOTES.md). A known, documented, reproducible
limitation is stronger in an interview than a gap nobody noticed.

---

## 2. Billing job and idempotency — ~12 tests

`tests/Feature/BillingRunTest.php`

### The headline test

```php
test('twelve months of billing produces twelve correct invoices', function () {
    $sub = subscription(plan: monthly(50000), start: '2026-01-01');

    foreach (range(1, 12) as $month) {
        Carbon::setTestNow(CarbonImmutable::parse('2026-01-01')->addMonths($month));
        $this->artisan('billing:run');
    }

    expect($sub->invoices()->count())->toBe(12);
    expect($sub->invoices()->sum('total_paise'))->toBe(600000);  // Rs 6000
});
```

One test, a full year, milliseconds. This is the test to open the conversation
with.

### Idempotency

```php
test('running the billing job three times produces one invoice', function () {
    $sub = dueSubscription();

    $this->artisan('billing:run');
    $this->artisan('billing:run');
    $this->artisan('billing:run');

    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(1);
});
```

- `test_concurrent_runs_produce_one_invoice` — two workers, one unique constraint
- `test_a_crash_mid_run_leaves_no_partial_invoice` — force an exception after the
  invoice insert, assert the period did not advance either
- `test_the_unique_constraint_is_what_stops_it` — insert a duplicate directly and
  assert the database rejects it, proving the guarantee is not in application code

### Selection rules

- `test_suspended_subscriptions_are_not_billed`
- `test_cancelled_subscriptions_are_not_billed`
- `test_trialing_subscriptions_are_not_billed_until_trial_ends`
- `test_subscriptions_not_yet_due_are_skipped`
- `test_past_due_subscriptions_are_still_billed`

---

## 3. Dunning — ~15 tests

`tests/Feature/DunningTest.php`

### The schedule

```php
test('four failed attempts suspend the subscription', function () {
    $sub = activeSubscription();
    gateway()->alwaysFails('card_declined');

    $this->artisan('billing:run');            // attempt 1 fails
    expect($sub->fresh()->status)->toBe('past_due');

    travelDays(1);  runRetries();             // attempt 2
    travelDays(3);  runRetries();             // attempt 3
    travelDays(5);  runRetries();             // attempt 4

    expect($sub->fresh()->status)->toBe('suspended');
    expect($sub->invoices()->first()->attempts()->count())->toBe(4);
});
```

### Recovery

- `test_payment_success_on_attempt_two_returns_to_active`
- `test_success_clears_the_retry_schedule` — no orphan retry jobs left queued
- `test_success_resets_the_attempt_counter`
- `test_payment_after_suspension_reactivates`
- `test_reactivation_restarts_the_period_from_the_payment_date` — the suspended
  days are not billed (the policy decision in TECHNICAL_SPEC §5)

### The state machine itself

- `test_illegal_transition_throws` — `suspended -> past_due` must raise, not no-op
- `test_every_transition_is_recorded`
- `test_retry_intervals_are_one_three_five_days`

### Failure codes

- `test_card_declined_and_insufficient_funds_follow_the_same_schedule`
- `test_the_failure_code_is_stored_on_the_attempt`

---

## 4. The invariant test — 1 test, high value

`tests/Feature/ChaosTest.php`

```php
test('customer balances stay consistent under 500 random events', function () {
    $customers = Customer::factory()->count(20)->create();

    foreach (range(1, 500) as $i) {
        fireRandomEvent($customers->random());
        // upgrade | downgrade | cancel | pay | fail payment | advance clock
    }

    foreach ($customers as $c) {
        expect($c->invoicedTotalPaise() - $c->paidTotalPaise())
            ->toBe($c->outstandingPaise());
    }
});
```

**What this buys in an interview:** "I ran 500 random operations against it and
the balance never disagreed with the ledger once." That sentence is not available
to a project built out of individually-tested CRUD endpoints.

Seed the randomness so a failure is reproducible.

---

## 5. Invoice integrity — ~8 tests

`tests/Feature/InvoiceTest.php`

- `test_line_items_sum_to_the_invoice_total` — the invariant from TECHNICAL_SPEC §2
- `test_credits_are_negative_lines`
- `test_invoice_numbers_are_sequential`
- `test_invoice_numbers_have_no_gaps_after_a_rollback` — force a failure between
  number allocation and commit, assert the next invoice reuses the number
- `test_invoice_numbers_restart_each_year`
- `test_concurrent_invoice_creation_does_not_duplicate_a_number`
- `test_a_paid_invoice_cannot_be_modified`
- `test_voiding_an_invoice_does_not_delete_it`

---

## 6. Money representation — ~5 tests

`tests/Unit/MoneyTest.php`

- `test_all_money_columns_are_bigint` — read the schema, assert no `float`/`double`
  anywhere. A schema test, not a logic test, and it catches the mistake at the
  only point it can be cheaply caught.
- `test_money_formatting_is_display_only`
- `test_paise_to_rupees_conversion_never_enters_persistence`

---

## Running

```bash
php artisan test                      # all
php artisan test --filter=Proration   # just the core
php artisan test --parallel
```

The proration suite must stay under a second. If it slows down, something has
leaked a database call into a pure function.
