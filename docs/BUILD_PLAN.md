# Build Plan

Six phases, in order. Roughly ten working days.

The rule throughout: **the core is built before anything that displays it.** UI
comes late because a proration engine with no screen is still a working proration
engine, while a screen with no engine is a mockup.

---

## Phase 1 — Foundation (1 day)

**Goal:** `php artisan migrate` runs clean and the container comes up.

- Laravel 12, PHP 8.3, PostgreSQL 16, Redis — Docker Compose
- Migrations for all eight tables ([TECHNICAL_SPEC.md](TECHNICAL_SPEC.md) §2)
- Models with relationships, casts, and **`price_paise` style naming enforced from
  the first migration** — renaming money columns later is how a float sneaks in
- The `UNIQUE (subscription_id, period_start)` constraint goes in **now**, not
  when idempotency is implemented. It is the design, not an optimisation.
- Factories for every model
- Pest installed, one smoke test green

**Done when:** `docker compose up`, `php artisan migrate`, `php artisan test` all pass.

---

## Phase 2 — Proration engine (2–3 days) — the core

**Goal:** `ProrationCalculator` is complete and pinned by ~30 tests.

**No database. No UI. No routes.** One class and one test file.

- `ProrationCalculator::calculate()` as a pure function
- `ProrationResult` as a small value object — `creditPaise`, `chargePaise`, `netPaise`
- Every case in [TECHNICAL_SPEC.md](TECHNICAL_SPEC.md) §3
- Every test in [TEST_PLAN.md](TEST_PLAN.md) §1
- The same-day-double-change test **written and left failing**, marked `todo`

**Spend the extra day here if it needs one.** This phase is the project. Everything
after it is plumbing that carries these numbers to a screen.

**Done when:** ~30 tests green, one `todo`, and the suite runs in under a second.

---

## Phase 3 — Invoices and the billing job (2 days)

**Goal:** `php artisan billing:run` works and cannot double-charge.

- Invoice + line item creation
- Gapless invoice numbering ([TECHNICAL_SPEC.md](TECHNICAL_SPEC.md) §7)
- `billing:run` command, scheduled daily
- Idempotency — catch the unique violation, count it, move on
- The twelve-month test and the run-three-times test ([TEST_PLAN.md](TEST_PLAN.md) §2)
- Plan change wired to the calculator, writing real invoice lines inside a transaction

**Done when:** running the job three times in a row produces one invoice, and
twelve simulated months produce twelve invoices totalling the right amount.

---

## Phase 4 — Gateway and dunning (2 days)

**Goal:** a failed payment walks the full retry schedule and suspends.

- `PaymentGateway` interface, `FakeGateway`, `NullGateway`
- Payment attempt job, dispatched on invoice creation
- Retry scheduling — +1, +3, +5 days
- The state machine, with **illegal transitions throwing**
- Recovery: success at any point resets status, counter and pending retries
- Suspension skips billing
- All of [TEST_PLAN.md](TEST_PLAN.md) §3

**Done when:** a customer can be driven failed → failed → failed → suspended →
paid → active, entirely in a test, with every transition recorded.

---

## Phase 5 — UI and seeder (2 days)

**Goal:** the demo in [DEMO_SCRIPT.md](DEMO_SCRIPT.md) can be performed end to end.

- The five screens in [UI_FLOW.md](UI_FLOW.md) — Blade + Tailwind, defaults only
- **The proration preview endpoint** — the single most important screen
- Dunning timeline rendered from `payment_attempts`
- PDF invoice
- **`DemoSeeder`** — eight months of history, fifty customers, all five statuses,
  one customer deep in dunning, one who recovered

The seeder is not a chore at the end. It is what makes the demo read as real
([UI_FLOW.md](UI_FLOW.md), seed data section). Budget for it properly.

**Done when:** the four-minute demo runs without touching the database by hand.

---

## Phase 6 — Deploy and document (1 day)

**Goal:** a live URL in the README.

- Railway or Render, with a managed Postgres
- Demo login in the README
- **Real screenshots** replacing the ASCII sketches in [UI_FLOW.md](UI_FLOW.md)
- README rewritten to describe what exists, with the "not built yet" banner removed
- README numbers taken from the actual test run, not estimated
- CI — lint and tests on push

**Done when:** someone can click a link and see the dashboard without installing
anything.

---

## What is not in any phase

Deliberately, and each for a reason given in the README:

- Real Razorpay integration
- Multi-currency
- Tax and GST
- Usage-based metering
- Customer self-service portal
- Email delivery (invoices render; sending them is a different problem)

---

## The habit that matters more than the phases

**Write `DECISIONS.md` as you go, not afterwards.**

Every time a choice is made that could have gone another way, three lines:

```markdown
## 2026-09-20 — Rounding direction on proration

Credit rounds up, charge rounds down, so the remainder goes to the customer.
Considered rounding both to nearest, but then the direction depends on the
numbers and is impossible to explain in a support conversation. Costs at most
one paisa per plan change.
```

Written at the time, this file is the honest record of the reasoning. Written
afterwards from memory, it is a reconstruction — and it reads like one.

It is also the file that makes "why did you do it that way?" a comfortable
question rather than a threatening one.
