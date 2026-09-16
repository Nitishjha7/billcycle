# Demo Script

A four-minute walkthrough, screen by screen, with what to say. The screens
themselves are specified in [UI_FLOW.md](UI_FLOW.md).

---

## What makes a demo read as real

Every beginner demo looks the same: three customers, everything paid, everything
green. An interviewer recognises it in five seconds.

Real systems are **partly broken at all times**. Some payments failed. Some
customers are suspended. Some numbers are ugly because they came out of a
calculation rather than a form.

| Toy demo | Real demo |
|---|---|
| 3 customers, all active | 50 customers - 42 active, 5 past due, 3 suspended |
| Every invoice paid | 8 overdue, 3 in dunning, 1 written off |
| Round numbers (Rs 1,000) | Rs 1,247.50 - the output of proration |
| Everything dated today | 8 months of history |
| Everything works | **One limitation you point out yourself** |

That last row is the strongest signal in the list. Someone hiding weaknesses does
not volunteer them.

---

## Before you start

- Seeder has been run: `php artisan db:seed --class=DemoSeeder`
- Browser open on the dashboard
- **A terminal open beside the browser** - needed for the idempotency moment
- Both visible at once, so nothing has to be alt-tabbed mid-sentence

---

## The script

### 1. Dashboard - 20 seconds

Open on the dashboard.

> "This is a subscription billing system. What is on screen is eight months of
> seeded history - about fifty customers."

Let them read the numbers. `MRR Rs 2,41,750 | Active 42 | Past due 5 | Suspended 3`

> "Not everyone is healthy, which is the normal state of a billing system."

**Purpose:** establishes scale and that the data is not three hand-made rows.

---

### 2. Customer list - 15 seconds

Click through to customers. Scroll once.

> "Five statuses - active, trialing, past due, suspended, cancelled."

**Purpose:** shows the state model exists before explaining it.

---

### 3. Proration preview - 60 seconds

**This is the core of the demo. Do not rush it.**

Open an active customer, click Change plan, select Pro from the dropdown.

The preview box appears **before** confirming:

```
Today is 15 Sep 2026. Cycle: 01 Sep - 01 Oct. 15 of 30 days remain.

Unused Basic (15 days)             - Rs 250.00
Pro for remaining 15 days          + Rs 600.00
-----------------------------------------------
Charged today                        Rs 350.00
```

> "They are on Rs 500 a month and moving to Rs 1,200, halfway through the cycle.
> They have already paid for fifteen days they will not use on the old plan, so
> that is credited. The new plan is charged for those same fifteen days. Net
> Rs 350 today."

Pause. Then:

> "The days come from the real cycle length, not a hardcoded thirty. February is
> twenty-eight days, so the daily rate is different - that is a test case, not an
> assumption."

And:

> "All of this is integer paise. Rs 250 is twenty-five thousand paise. There is no
> float anywhere in the money path, because dividing a monthly price across a
> month and doing it a few thousand times is exactly where float errors
> accumulate."

**Purpose:** this is the project. Sixty seconds well spent here is worth more
than the rest of the demo combined.

**Likely interruption:** *"What about a downgrade?"* - Good. Show it: the preview
shows a credit and says "applied to your next invoice, no refund." Explain that
refunds are a gateway operation with their own failure modes, so the system
issues credit instead - a stated policy, not an oversight.

---

### 4. Dunning timeline - 45 seconds

Go to the past-due customer.

```
o  12 Aug  Invoice issued                   Rs 1,200
o  12 Aug  Attempt 1 - FAILED               card_declined
|          next retry: +1 day
o  13 Aug  Attempt 2 - FAILED               insufficient_funds
|          next retry: +3 days
o  16 Aug  Attempt 3 - FAILED               card_declined
|          next retry: +5 days
o  21 Aug  SUBSCRIPTION SUSPENDED
```

> "When a payment fails the system retries on a widening schedule - one day, three
> days, five days. Widening because an immediate retry on a declined card just
> declines again, but insufficient funds might clear on payday."

> "If a payment succeeds at any point in here, everything resets - status back to
> active, pending retries cancelled. And a suspended subscription stops being
> billed, so it does not quietly accumulate debt while the customer has no access."

**Purpose:** shows a state machine that thought about recovery, not just failure.

---

### 5. Idempotency, live - 30 seconds

**The strongest thirty seconds in the demo.**

Switch to the terminal.

> "This billing job runs every night."

```bash
php artisan billing:run
# Generated 4 invoices
```

Refresh the browser. Four new invoices.

> "Now watch what happens when it runs again - a cron double-fires, or a deploy
> restarts the worker mid-run."

```bash
php artisan billing:run
# Generated 0 invoices (4 already billed this cycle)
```

Refresh the browser. **Nothing changed.**

> "That is not application code remembering to check. There is a unique constraint
> on subscription and period start, so the second insert is rejected by the
> database. A select-then-insert check would have a race window - two workers can
> both pass it. Charging a customer twice is real money, so the guarantee belongs
> in the schema."

**Purpose:** this is the moment that separates the project from CRUD. It is
concrete, it is live, and the reasoning is a senior-level answer.

---

### 6. Name your own limitation - 20 seconds

Do not skip this.

> "One case I have not solved: if a customer changes plan twice on the same day,
> the second change re-credits time that the first one already credited. There is
> a test for it and it currently fails - it is marked as a known limitation in the
> README."

> "The fix is to prorate from the last change rather than the cycle start, but it
> needs the change history threaded through the calculator and I did not want to
> complicate the pure function until I had a real case for it."

**Purpose:** volunteering a reproducible, understood, documented limitation is the
single most credible thing available in a demo. It also steers the conversation
onto ground you have already thought about.

---

## Total: about 3 minutes 10 seconds

Leave room. Interruptions are the point - a demo that runs uninterrupted for four
minutes means nobody was engaged.

---

## Questions that will come, and where the answer lives

| Question | Answer |
|---|---|
| "Why not use floats, they are easier?" | TECHNICAL_SPEC §1 |
| "What if the cycle is 28 days?" | Real cycle length is used; there is a test |
| "Why no real payment gateway?" | Fake gateway makes failure producible on demand - TECHNICAL_SPEC §6 |
| "What happens if the job crashes halfway?" | Transaction rolls back; invoice and period advance are atomic |
| "How do you know the numbers are right?" | The invariant test - 500 random events, balances still agree - TEST_PLAN §4 |
| "Have you tested a full year?" | One test, twelve months, milliseconds - TEST_PLAN §2 |
| "What would you do next?" | Same-day double change; then real gateway behind the existing interface |

---

## If the demo has to be two minutes

Cut to three moments:

1. **Proration preview** (60s) - the core
2. **Idempotency in the terminal** (30s) - the strongest proof
3. **Your own limitation** (20s) - the credibility

Skip the dashboard, the list, and the dunning timeline. Those support the story;
these three are the story.
