# Technical Specification

Schema, algorithms and the decisions behind them. Build order is in
[BUILD_PLAN.md](BUILD_PLAN.md); the tests that pin this behaviour are in
[TEST_PLAN.md](TEST_PLAN.md).

---

## Contents

1. [Money representation](#1-money-representation)
2. [Schema](#2-schema)
3. [Proration](#3-proration)
4. [The billing job and idempotency](#4-the-billing-job-and-idempotency)
5. [Dunning](#5-dunning)
6. [The fake payment gateway](#6-the-fake-payment-gateway)
7. [Invoice numbering](#7-invoice-numbering)
8. [Time and testability](#8-time-and-testability)

---

## 1. Money representation

**Every monetary value is a PHP `int` holding paise.** Rs 1,247.50 is `124750`.

Columns are named with the unit: `price_paise`, `credit_paise`, `total_paise`,
`amount_paise`. A unit error is then visible at the call site rather than three
layers down.

Database type is `BIGINT`. `DECIMAL` would also be exact, but it arrives back
from PDO as a *string*, which invites a silent `(float)` cast somewhere
downstream. An integer cannot be silently downgraded.

**Rounding.** Division happens in exactly one place — computing a daily rate —
and the result is rounded **once**, at the end of a proration calculation, with
the remainder resolved in favour of the customer. The choice is written down here
because "we round somewhere, probably down" is how a reconciliation mismatch is
born:

> When a proration amount does not divide evenly, the remainder goes to the
> **customer** (credit rounds up, charge rounds down). The maximum cost to the
> business is one paisa per plan change. The alternative — rounding in our own
> favour — is indefensible in a support conversation for the sake of 1 paisa.

---

## 2. Schema

Eight tables. UUID primary keys throughout.

### `plans`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `name` | varchar | "Basic", "Pro" |
| `price_paise` | bigint | |
| `interval` | enum | `monthly`, `yearly` |
| `trial_days` | int | 0 = no trial |
| `is_active` | bool | Inactive plans keep existing subscriptions alive but accept no new ones |

Plans are **never edited in place** once a subscription references them. A price
change creates a new plan row. Otherwise historical invoices become unexplainable —
the invoice says Rs 500 and the plan says Rs 700, and nothing records that the
plan changed in between.

### `customers`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `name`, `email` | varchar | `email` unique |

### `subscriptions`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `customer_id` | uuid fk | |
| `plan_id` | uuid fk | Current plan |
| `status` | enum | `trialing`, `active`, `past_due`, `suspended`, `cancelled` |
| `current_period_start` | date | |
| `current_period_end` | date | |
| `cancel_at_period_end` | bool | Cancellation is scheduled, not immediate |
| `trial_ends_at` | date null | |

`status` is **stored, not derived.** Derivation from dates would be tempting, but
`past_due` depends on payment history and `suspended` on how many retries have
been exhausted — neither is a function of the calendar alone.

### `invoices`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `subscription_id` | uuid fk | |
| `number` | varchar | Sequential, gapless — see §7 |
| `period_start`, `period_end` | date | |
| `subtotal_paise`, `total_paise` | bigint | |
| `status` | enum | `open`, `paid`, `void` |
| `issued_at` | timestamp | |

> **`UNIQUE (subscription_id, period_start)`** — this single constraint is what
> makes the billing job idempotent. See §4.

### `invoice_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `invoice_id` | uuid fk | |
| `description` | varchar | "Unused Basic (15 days)" |
| `amount_paise` | bigint | **Signed** — credits are negative |
| `type` | enum | `subscription`, `proration_credit`, `proration_charge` |

Credits are negative lines on a normal invoice rather than a separate credit-note
entity. An invoice then always reads top to bottom as an arithmetic sum, and
`SUM(amount_paise) == invoices.total_paise` is a checkable invariant.

### `payments`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `invoice_id` | uuid fk | |
| `amount_paise` | bigint | |
| `status` | enum | `succeeded`, `failed` |
| `gateway_reference` | varchar | |

### `payment_attempts`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `invoice_id` | uuid fk | |
| `attempt_number` | int | 1, 2, 3, 4 |
| `failure_code` | varchar null | `card_declined`, `insufficient_funds` |
| `attempted_at` | timestamp | |
| `next_retry_at` | timestamp null | Null on the final attempt |

Attempts are kept **separate from payments** because the dunning timeline is a
first-class thing the UI renders (see [UI_FLOW.md](UI_FLOW.md)). Folding failures
into `payments` with a status column would make "show me what happened to this
invoice" a filtered query over a table that mostly means something else.

### `plan_changes`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `subscription_id` | uuid fk | |
| `from_plan_id`, `to_plan_id` | uuid fk | |
| `changed_at` | timestamp | |
| `credit_paise`, `charge_paise`, `net_paise` | bigint | What was computed at the time |

The computed amounts are **stored**, not recomputed on demand. Recomputation
would silently change historical answers the day the proration rules are
adjusted, and the invoice that was actually sent would no longer be reproducible.

---

## 3. Proration

### The calculation

```php
ProrationCalculator::calculate(
    Plan $oldPlan,
    Plan $newPlan,
    CarbonImmutable $changeDate,
    CarbonImmutable $cycleStart,
    CarbonImmutable $cycleEnd,
): ProrationResult
```

Pure function. No database access, no `now()`, no side effects. The change date is
passed in rather than read from the clock, so the function is trivially testable
and the caller owns the timing decision.

**Steps:**

1. `totalDays = cycleStart.diffInDays(cycleEnd)` — the cycle actual length, not 30.
2. `remainingDays = changeDate.diffInDays(cycleEnd)` — days paid for but not used on the old plan.
3. `credit = oldPlan.price_paise * remainingDays / totalDays` — rounded **up**.
4. `charge = newPlan.price_paise * remainingDays / totalDays` — rounded **down**.
5. `net = charge - credit`.

**`totalDays` is the real cycle length.** A 28-day February and a 31-day March
produce different daily rates for the same monthly plan. Using a fixed 30 would
make the February daily rate wrong by 7%.

### Cases that must be handled

| Case | Behaviour | Why |
|---|---|---|
| **Upgrade mid-cycle** | Net positive, charged immediately | Customer gets more value now; collect now |
| **Downgrade mid-cycle** | Net negative, credit carried to next invoice, **no refund** | A refund is a gateway operation with its own failure modes; a credit is a line item. Documented as a deliberate limitation. |
| **Change on cycle start day** | `remainingDays == totalDays`, full credit and full charge | Degenerates correctly; no special case needed |
| **Change on cycle end day** | `remainingDays == 0`, both zero, net zero | Must not divide by zero or emit a Rs 0.00 line |
| **Same-day second change** | Second change prorates from the *same* change date | Two changes in one day must not double-credit. **This is the known weak spot — see [INTERVIEW_NOTES.md](INTERVIEW_NOTES.md).** |
| **Change during trial** | No proration; plan swaps, trial end unchanged | Nothing has been paid, so there is nothing to prorate |
| **Same plan to same plan** | Rejected before calculation | Not an error state, just a no-op the UI should not offer |

### Where it is called

The calculator is called **twice** for a single plan change:

1. From the **preview endpoint**, to render the confirmation box the user sees
   before confirming (see [UI_FLOW.md](UI_FLOW.md) §3).
2. From the **apply path**, inside a transaction, to produce the real invoice lines.

Both calls pass the same inputs and must produce the same output — which is
exactly what a pure function guarantees, and what a method reading `now()`
internally would not.

---

## 4. The billing job and idempotency

### The job

`php artisan billing:run`, scheduled daily. For each subscription whose
`current_period_end` is today or past, and whose status is `active` or `past_due`:

1. Open a transaction.
2. Create the invoice for the *next* period.
3. Add the subscription line.
4. Advance `current_period_start` / `current_period_end`.
5. Commit.
6. Dispatch a payment attempt job.

### Why it cannot double-charge

**`UNIQUE (subscription_id, period_start)` on `invoices`.**

The second run attempts the same insert, the database rejects it, the job catches
the unique violation and counts it as "already billed". No `SELECT ... IF NOT
EXISTS` check, because that check has a race window between the read and the
write — two workers can both pass it.

This is the difference between *remembering* to be idempotent and *being*
idempotent. The guarantee lives in the schema, so it holds even when the
application code is wrong.

### What the job must survive

- **Running twice in a row** — second run creates nothing.
- **Running twice concurrently** — one worker wins the unique constraint, the other no-ops.
- **Crashing mid-run** — the transaction rolls back; period advance and invoice creation are atomic, so a re-run finds a clean state.
- **A customer cancelled between runs** — the status check happens inside the transaction.

---

## 5. Dunning

### The state machine

```
                    invoice issued
                          |
                          v
                   +-------------+
                   |   ACTIVE    |<--------------+
                   +-------------+               |
                          |                      |
                  payment fails                  | payment
                          |                      | succeeds
                          v                      |
                   +-------------+               |
        +--------->|  PAST_DUE   |---------------+
        |          +-------------+
        |                 |
   retry fails    attempt 4 fails
   (attempts 1-3)         |
        |                 v
        |          +-------------+
        +----------|  SUSPENDED  |
                   +-------------+
                          |
                   payment succeeds
                          |
                          v
                       ACTIVE
```

### Retry schedule

| Attempt | When | On failure |
|---|---|---|
| 1 | Immediately on invoice | Schedule attempt 2 at +1 day |
| 2 | +1 day | Schedule attempt 3 at +3 days |
| 3 | +3 days | Schedule attempt 4 at +5 days |
| 4 | +5 days | **Suspend** |

Intervals widen rather than staying fixed: an immediate retry after a
`card_declined` will decline again, whereas `insufficient_funds` may resolve on
payday. Widening covers both without a per-failure-code branch.

### Rules that must hold

- **Success at any point resets everything** — status to `active`, pending retry jobs cancelled, attempt counter cleared.
- **Suspension is not cancellation** — the subscription still exists, still has a plan, and can be revived by a single successful payment.
- **A suspended subscription is not billed** — the billing job skips it. It must not accumulate debt while the customer has no access.
- **Illegal transitions throw.** `suspended -> past_due` is not reachable, and attempting it is a bug, not a no-op.

### The open question, answered

> A customer is suspended for 6 days, then pays. Do they owe anything for those 6 days?

**No.** Access was suspended, so the period is not billed. On reactivation the
period restarts from the payment date. This is a **policy decision**, written
down here because it is exactly the kind of thing an interviewer probes, and
"I had not thought about it" is the wrong answer.

---

## 6. The fake payment gateway

A `PaymentGateway` interface with two implementations:

```php
interface PaymentGateway {
    public function charge(int $amountPaise, string $reference): GatewayResult;
}
```

- `FakeGateway` — outcome driven by configuration. Can be told to fail a specific
  customer, fail with a specific code, or fail the first N attempts then succeed.
- `NullGateway` — always succeeds. Used in tests that are not about payment failure.

**Why not integrate Razorpay.** A real gateway makes failure *hard to produce* —
which is backwards, because failure is the whole subject of §5. With a fake
gateway a test can say "this card declines twice then succeeds" in one line. The
interface is the seam a real gateway would slot into, and that seam existing is
the part that matters.

This is an explicit trade-off, not an omission, and it is stated in the README and
in [INTERVIEW_NOTES.md](INTERVIEW_NOTES.md).

---

## 7. Invoice numbering

Format: `INV-2026-000123`. Sequential within a year, **gapless**.

Gapless is harder than it sounds: an auto-increment column leaves holes whenever a
transaction rolls back, and holes in an invoice sequence are an audit problem in
most jurisdictions.

Approach: a dedicated `invoice_sequences` table holding `(year, last_number)`,
read with `SELECT ... FOR UPDATE` inside the same transaction that creates the
invoice. The row lock serialises number allocation; the shared transaction means a
rollback un-allocates the number.

Cost: invoice creation serialises on that row. At this scale that is irrelevant,
and the alternative — gaps — is a correctness problem rather than a performance one.

---

## 8. Time and testability

Nothing calls `now()` directly inside billing logic. Either the time is a
parameter (as in `ProrationCalculator`), or it comes from an injected clock.

Tests drive the clock with `Carbon::setTestNow()`, which makes a full year of
billing runnable in milliseconds:

```php
foreach (range(1, 12) as $month) {
    Carbon::setTestNow($start->addMonths($month));
    $this->artisan('billing:run');
}
```

This is what makes the tests in [TEST_PLAN.md](TEST_PLAN.md) §1 possible at all. A
system that reads the wall clock internally can only be tested by waiting.
