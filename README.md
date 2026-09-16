<div align="center">

# BillCycle

**Recurring subscription billing for SaaS — proration, dunning, and a billing job that cannot double-charge.**

</div>

> **Status: not built yet.** This repository currently contains the specification
> and build plan only. Everything below describes what is *intended*, not what
> exists. This README will be rewritten with real screenshots and real numbers
> once the code lands — and it will not claim anything the code does not do.

---

## The problem

Any SaaS that charges monthly hits the same three problems, and none of them are CRUD.

**1. Someone changes plan mid-cycle.** A customer on ₹500/month upgrades to
₹1,200/month on the 15th. They have already paid for a full month of the cheaper
plan, and 15 days of it are unused. What do you charge them *today*? Get this
wrong and you either rob the customer or lose money — quietly, on every upgrade,
forever.

**2. Payments fail.** Cards expire, balances run out, banks decline. A failed
payment is not the end of the relationship — it is the start of a retry schedule.
Give up too early and you lose a paying customer; never give up and you serve a
customer who stopped paying months ago.

**3. The billing job runs again.** Cron fires twice. A deploy restarts the worker
mid-run. The queue retries a job that actually succeeded. If invoice generation
is not idempotent, a customer is charged twice — and that is real money, not a
bad row in a table.

BillCycle is built around those three, and deliberately nothing else.

---

## Overview

| | |
|---|---|
| **Backend** | Laravel 12, PHP 8.3 |
| **Data** | PostgreSQL 16 — all money as integer paise |
| **Async** | Queues + Horizon, Laravel Scheduler |
| **Frontend** | Blade + Tailwind (server-rendered, no SPA) |
| **Payments** | A fake gateway with controllable outcomes — no real Razorpay |
| **Infra** | Docker Compose |
| **Tests** | Pest, targeting ~70 |

---

## The three things worth reading the code for

### 1. Proration is a pure function

```php
ProrationCalculator::calculate($oldPlan, $newPlan, $changeDate, $cycleStart, $cycleEnd)
// → ['credit_paise' => 25000, 'charge_paise' => 60000, 'net_paise' => 35000]
```

No database, no clock, no side effects — inputs in, amounts out. That is what
makes the ~30 edge-case tests around it cheap to write and fast to run: a
28-day month, a same-day double change, an upgrade during trial, a downgrade
that leaves credit behind.

### 2. Dunning is a state machine, not a cron with `if`s

```
payment fails → retry +1d → retry +3d → retry +5d → SUSPENDED
                    ↓ payment succeeds at any point ↓
                          back to ACTIVE, schedule cleared
```

Illegal transitions are rejected in code, not merely avoided by convention.

### 3. The billing job is idempotent by construction

```bash
php artisan billing:run   # Generated 4 invoices
php artisan billing:run   # Generated 0 invoices (4 already billed this cycle)
php artisan billing:run   # Generated 0 invoices
```

A unique constraint on `(subscription_id, period_start)` makes the second run a
no-op at the database level — not because application code remembered to check.

---

## Money is never a float

Every monetary value in this system is an **integer count of paise**, and every
column and variable says so: `price_paise`, `credit_paise`, `total_paise`.

`0.1 + 0.2 !== 0.3` is not a trivia question when you are dividing a monthly
price across 30 days, taking 15 of them, and doing it a few thousand times a
month. Rounding is applied once, explicitly, at a named boundary — and which way
it rounds is a documented decision, not an accident of the language.

---

## Documentation

| Doc | What is in it |
|---|---|
| [docs/TECHNICAL_SPEC.md](docs/TECHNICAL_SPEC.md) | Schema, proration algorithm, dunning state machine, idempotency design |
| [docs/BUILD_PLAN.md](docs/BUILD_PLAN.md) | Six phases, in build order, with what "done" means for each |
| [docs/TEST_PLAN.md](docs/TEST_PLAN.md) | Every test to write, grouped, with the edge cases spelled out |
| [docs/DEMO_SCRIPT.md](docs/DEMO_SCRIPT.md) | The four-minute walkthrough, screen by screen |
| [docs/UI_FLOW.md](docs/UI_FLOW.md) | The five screens, what each shows, and the seed data behind them |
| [docs/INTERVIEW_NOTES.md](docs/INTERVIEW_NOTES.md) | Pitch, trade-offs, known limitations, anticipated questions |
| [docs/SETUP.md](docs/SETUP.md) | Getting it running locally |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Railway + Neon, why those, and what to verify after |
| [DECISIONS.md](DECISIONS.md) | A running log of every non-obvious decision, written as it is made |

---

## Deliberate non-goals

Scope discipline is part of the design. These are **not** being built, and each
was rejected for a reason:

- **Real payment gateway integration** — a fake gateway with controllable
  outcomes tests the dunning logic *better*, because failures can be produced on
  demand instead of hoped for.
- **Multi-currency** — would add conversion and rounding concerns that dilute the
  proration work rather than deepen it.
- **Tax / GST rules** — jurisdiction rules are a research problem, not an
  engineering one.
- **Usage-based metering** — a different billing model entirely; it would double
  the surface area and halve the depth.

---

## License

MIT
