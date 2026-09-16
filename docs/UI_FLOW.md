# UI Flow

The five screens, what each one shows, and the seed data that makes them look
real. The timed walkthrough is in [DEMO_SCRIPT.md](DEMO_SCRIPT.md).

Blade + Tailwind, server-rendered. No SPA — the point of this project is that it
is a classic server-rendered business application, which is what Laravel is for.

---

## Design rule: do not spend time on beauty

Tailwind defaults. Tables, badges, cards. No animation, no custom design system.

An interviewer is not judging the CSS. They are looking at **what is behind the
screen**. The proration preview box can look plain — the numbers in it are the
thing being demonstrated.

Time saved on visual polish goes into the seed data instead, which is what
actually decides whether the demo reads as real.

---

## The five screens

| # | Route | Screen | The thing it exists to show |
|---|---|---|---|
| 1 | `/` | Dashboard | Scale and mess — this is a running system |
| 2 | `/customers` | Customer list | Not everyone is healthy |
| 3 | `/customers/{id}` | Customer detail | Dunning timeline |
| 4 | `/customers/{id}/change-plan` | Plan change | **Proration preview — the core** |
| 5 | `/invoices/{id}` | Invoice detail | Line items, including negative ones |

That is the whole application. Anything else is scope creep.

---

## 1. Dashboard

```
+----------------------------------------------------------+
|  BillCycle                                               |
+----------------------------------------------------------+
|                                                          |
|   MRR              Active        Past due     Suspended  |
|   Rs 2,41,750      42            5            3          |
|                                                          |
|   Overdue invoices: 8            In dunning: 3           |
|                                                          |
|   Recent activity                                        |
|   16 Sep  Invoice INV-2026-000412 issued     Rs 1,200    |
|   16 Sep  Payment failed - Priya Mehta       attempt 2   |
|   15 Sep  Plan changed - Rahul Sharma        +Rs 350     |
|   15 Sep  Subscription suspended - Amit K.   4 attempts  |
+----------------------------------------------------------+
```

**Seed requirements:**

- MRR must **not** be a round number. `Rs 2,41,750`, never `Rs 2,40,000`.
- Suspended and past-due counts must be non-zero. A dashboard where everything is
  green reads as a fixture.
- Recent activity must span several days, not all today.

---

## 2. Customer list

```
+------------------------------------------------------------------+
|  Customers                                     [search______]    |
+------------------------------------------------------------------+
|  Name            Plan      Status         Next billing           |
|  --------------  --------  -------------  ---------------------  |
|  Rahul Sharma    Pro       * Active       15 Oct 2026            |
|  Priya Mehta     Basic     * Past due     3 attempts, next 19 Sep|
|  Amit Kumar      Pro       * Suspended    since 21 Aug           |
|  Sneha Patel     Basic     * Trialing     trial ends 22 Sep      |
|  Vikram Singh    Pro       * Active       02 Oct 2026            |
|  ...                                                             |
+------------------------------------------------------------------+
```

Status badges carry colour: green active, amber past due, red suspended, blue
trialing.

**Seed requirements:** all five statuses present. Roughly 50 customers, so the
list scrolls.

---

## 3. Customer detail — the dunning timeline

The screen has three sections: subscription summary, invoice history, and — for
a customer in trouble — the dunning timeline.

```
+----------------------------------------------------------+
|  Priya Mehta                          priya@example.com  |
|  Basic - Rs 500/month          Status: * Past due        |
|  Period: 01 Sep - 01 Oct 2026                            |
+----------------------------------------------------------+
|                                                          |
|  Payment timeline - INV-2026-000387                      |
|                                                          |
|  o  12 Aug  Invoice issued                   Rs 1,200    |
|  |                                                       |
|  o  12 Aug  Attempt 1 - FAILED               card_declined
|  |          next retry: +1 day                           |
|  |                                                       |
|  o  13 Aug  Attempt 2 - FAILED               insufficient_funds
|  |          next retry: +3 days                          |
|  |                                                       |
|  o  16 Aug  Attempt 3 - FAILED               card_declined
|  |          next retry: +5 days                          |
|  |                                                       |
|  o  21 Aug  SUBSCRIPTION SUSPENDED                       |
|                                                          |
+----------------------------------------------------------+
|  Invoices                                                |
|  INV-2026-000387   01 Aug - 01 Sep   Rs 1,200   * Open   |
|  INV-2026-000341   01 Jul - 01 Aug   Rs 1,200   * Paid   |
|  INV-2026-000298   01 Jun - 01 Jul   Rs   500   * Paid   |
+----------------------------------------------------------+
```

The timeline is a vertical list rendered from `payment_attempts` — which is
exactly why that table is separate from `payments` (TECHNICAL_SPEC §2).

Note the third invoice: `Rs 500` where the later two are `Rs 1,200`. That is a
real upgrade in the history, and it makes the data look lived-in rather than
generated.

---

## 4. Plan change — the proration preview

**This is the most important screen in the application.** It is where the core
logic becomes visible.

The flow has two steps, and the first step is the whole point:

**Step 1 — select a plan. Preview appears immediately, before anything is committed.**

```
+---------------------------------------------------------+
|  Change plan - Rahul Sharma                             |
|                                                         |
|  Current plan:  Basic    Rs 500/month                   |
|  New plan:      [ Pro - Rs 1,200/month    v ]           |
|                                                         |
|  +---------------------------------------------------+  |
|  |  Preview                                          |  |
|  |                                                   |  |
|  |  Today is 15 Sep 2026. Cycle: 01 Sep - 01 Oct.    |  |
|  |  15 of 30 days remain.                            |  |
|  |                                                   |  |
|  |  Unused Basic (15 days)             - Rs 250.00   |  |
|  |  Pro for remaining 15 days          + Rs 600.00   |  |
|  |  -----------------------------------------------  |  |
|  |  Charged today                        Rs 350.00   |  |
|  |                                                   |  |
|  |  Next invoice: 01 Oct 2026, Rs 1,200.00           |  |
|  +---------------------------------------------------+  |
|                                                         |
|              [ Cancel ]   [ Confirm change ]            |
+---------------------------------------------------------+
```

**Why the preview matters more than the action.** Without it, the proration logic
runs invisibly inside a POST and the interviewer sees a plan name change. With
it, the arithmetic is on screen, and the natural next question is "how do you
handle February?" — which is the conversation the project was built to have.

The preview calls `ProrationCalculator` with the same inputs the apply path will
use. Because the calculator is pure, preview and reality cannot disagree.

**Downgrade variant** — the preview must show a negative net and explain what
happens to it:

```
|  Unused Pro (15 days)               - Rs 600.00   |
|  Basic for remaining 15 days        + Rs 250.00   |
|  -----------------------------------------------  |
|  Credit applied to next invoice       Rs 350.00   |
|                                                   |
|  Nothing is charged today. Your next invoice      |
|  will be Rs 150.00 instead of Rs 500.00.          |
```

Saying "no refund, credit instead" **on the screen** turns a limitation into a
stated policy.

---

## 5. Invoice detail

```
+----------------------------------------------------------+
|  INV-2026-000412                          [ Download PDF ]|
|  Rahul Sharma - 15 Sep 2026                              |
+----------------------------------------------------------+
|                                                          |
|  Description                                    Amount   |
|  ---------------------------------------------  -------  |
|  Unused Basic (15 days)                      - Rs 250.00 |
|  Pro - 15 Sep to 01 Oct 2026                 + Rs 600.00 |
|  ---------------------------------------------  -------  |
|  Total                                         Rs 350.00 |
|                                                          |
|  Status: * Paid - 15 Sep 2026                            |
+----------------------------------------------------------+
```

The negative line is the visible proof that credits are line items rather than a
separate concept (TECHNICAL_SPEC §2).

---

## Empty states

Every list needs one. A new customer with no invoices shows
`No invoices yet - first invoice will be issued on 01 Oct 2026`, not a blank
table.

Small thing, but a blank region where data should be reads as unfinished.

---

## Seed data specification

`php artisan db:seed --class=DemoSeeder`

The seeder is the single most important piece of demo infrastructure in the
project. It must produce:

| Requirement | Why |
|---|---|
| **8 months of history** | Dates all in the last week look generated |
| **~50 customers** | Enough that lists scroll |
| **All five statuses present** | 42 active, 5 past due, 3 suspended, a few trialing, a couple cancelled |
| **Non-round amounts** | Proration produces `Rs 1,247.50`; only unprorated plans are round |
| **Real upgrade history** | Several customers should have changed plan mid-cycle at some point, leaving proration lines in old invoices |
| **One customer deep in dunning** | For the timeline screen — 3 failed attempts, suspended |
| **One customer who recovered** | Failed twice, then paid, back to active. Proves the reset path. |
| **Sequential invoice numbers** | Gapless across the whole seeded history |

A `--profile=messy` flag can raise the proportion of unhealthy accounts for
demonstration purposes.

**The seeder is not a fixture, it is the demo.** Time spent here shows up
directly in how the project reads.
