# Project Walkthrough

A running, plain-language account of what has actually been built, in the
order it was built, and why. [DECISIONS.md](../DECISIONS.md) records the
reasoning behind individual choices; this file records the *story* — what
exists right now, how it got here, and what to look at if you want to see it
working. Updated as each phase lands.

---

## What BillCycle is, in one paragraph

A recurring-billing backend for a SaaS product: plans, subscriptions,
invoices, and the three problems that make billing hard — prorating a
mid-cycle plan change correctly, retrying a failed payment on a sane
schedule before giving up, and generating invoices in a way that cannot
double-charge a customer even if the billing job runs twice. See the
[README](../README.md) for the full pitch and [TECHNICAL_SPEC.md](TECHNICAL_SPEC.md)
for the exact algorithms.

---

## Where things stand

| Phase | Status | What it means |
|---|---|---|
| 1 — Foundation | ✅ Done | `docker compose up`, `php artisan migrate`, `php artisan test` all pass |
| 2 — Proration engine | ✅ Core done | `ProrationCalculator` pure function, 16 tests + 1 documented `todo` |
| 3 — Invoices & billing job | ⏳ Not started | |
| 4 — Gateway & dunning | ⏳ Not started | |
| 5 — UI & seeder | ⏳ Not started | |
| 6 — Deploy & document | ⏳ Not started | |

---

## Phase 1 — Foundation

**Goal:** the container comes up and migrations run clean.

### What was built

- A Laravel 12 / PHP 8.3 skeleton, scaffolded via `composer create-project`
  inside a Docker container (no PHP or Composer installed on the host machine
  — everything runs through Docker, matching how the project is meant to be
  run anyway per [SETUP.md](SETUP.md)).
- `docker-compose.yml` with six services: `app` (PHP-FPM), `nginx`,
  `postgres` (16), `redis`, `worker` (queue), `scheduler` (`schedule:work`).
  Ports offset to 8004/5436 so this can run alongside other projects on the
  same machine, per SETUP.md.
- Migrations for all eight domain tables from
  [TECHNICAL_SPEC.md §2](TECHNICAL_SPEC.md#2-schema): `plans`, `customers`,
  `subscriptions`, `invoices` (plus a ninth, `invoice_sequences`, backing the
  gapless invoice numbering in §7), `invoice_lines`, `payments`,
  `payment_attempts`, `plan_changes`. UUID primary keys throughout.
- The `UNIQUE (subscription_id, period_start)` constraint on `invoices` went
  in with the very first migration, not later — it is the mechanism that
  makes the billing job idempotent (§4), so it belongs in the schema from day
  one rather than arriving as an "optimisation."
- Eloquent models with relationships and casts for every table, `price_paise`
  columns cast to `integer` explicitly.
- Factories for all eight models, with named states for the shapes the test
  suite will need later: `trialing()`, `pastDue()`, `suspended()`,
  `cancelled()` subscriptions; `paid()`/`void()` invoices; `failed()`
  payments and attempts; `prorationCredit()`/`prorationCharge()` line items.
- Pest installed (replacing the default PHPUnit `ExampleTest` scaffolding),
  with `tests/Pest.php` deliberately binding `RefreshDatabase` only to
  `Feature` tests — `Unit` tests (the proration suite) must stay
  database-free.
- One smoke test (`tests/Feature/SmokeTest.php`): create a customer, a plan,
  and a subscription through the real factories and assert the relationships
  resolve. This is what "the container comes up and the app actually works"
  looks like as a test, not just as a manual click-through.

### A bug worth knowing about

`composer create-project` was run inside the official `composer:2.8` Docker
image, which bundles its own PHP internally — not the PHP 8.3 the app
actually runs on. The resulting `composer.lock` silently locked packages
(`symfony/clock`, `nesbot/carbon`, and others) to versions that require PHP
8.4+. This didn't surface until `composer install` was run inside the real
`php:8.3-fpm` app image during the Docker build, which then failed outright.

Fixed by building a throwaway `php:8.3-cli` + Composer container and
re-running `composer update` inside *that*, so the lockfile was resolved
against the same PHP version the app actually runs on. Full writeup in
[DECISIONS.md](../DECISIONS.md#2026-09-17--composerlock-silently-drifted-to-php-84-only-packages).

**Lesson generalised:** a lockfile that installs is not the same as a
lockfile that installs on *your* runtime. Generate it inside the target
image, not a generic tool image.

### How to see it working

```bash
docker compose up -d --build
docker exec billcycle-app-1 php artisan migrate --force
docker exec billcycle-app-1 php artisan test
```

---

## Phase 2 — Proration engine (core)

**Goal:** `ProrationCalculator` complete and pinned by tests, no database, no UI.

### What was built

- `app/Billing/ProrationCalculator.php` — the pure function described in
  [TECHNICAL_SPEC.md §3](TECHNICAL_SPEC.md#3-proration). Takes two plans, a
  change date, and the cycle boundaries; returns a `ProrationResult` with
  `creditPaise`, `chargePaise`, `netPaise`. No database access, no `now()`,
  no side effects — the caller owns the clock.
- `app/Billing/ProrationResult.php` — a small immutable value object, nothing
  more.
- `app/Billing/PlanPricing.php` and `PlanSnapshot.php` — see the design note
  below; this is the one place Phase 2 deviated from the literal
  TECHNICAL_SPEC.md signature, and the spec doc has been updated to match.
- `tests/Unit/ProrationCalculatorTest.php` — 16 passing tests plus the one
  documented `todo()`, covering every case listed in
  [TEST_PLAN.md §1](TEST_PLAN.md#1-proration--30-tests): basic arithmetic,
  real cycle length (28/29/31/365-day cycles), the start/end/one-day-before
  boundaries, rounding direction and its one-paisa bound, free-plan edges,
  same-plan rejection, and the known same-day-double-change limitation left
  failing on purpose.

### A design decision worth explaining

TECHNICAL_SPEC.md originally sketched `ProrationCalculator::calculate()` as
taking the Eloquent `Plan` model directly. In practice, an Eloquent model —
even one that is never saved and never queried — still requires a database
connection resolver the moment you call a method inherited from `Model`.
That would have meant a supposedly "no database" unit-test suite was quietly
depending on one anyway, just not for anything it actually used the
connection for. It surfaced immediately as `Call to a member function
connection() on null` the first time the calculator tests ran.

Fixed by introducing a `PlanPricing` interface (just `pricePaise()` and
`planIdentity()`). `Plan` implements it for production code; tests use a
tiny `PlanSnapshot` DTO that implements the same interface with zero
framework dependency. The calculator's actual logic didn't change — only
what type it accepts. `TECHNICAL_SPEC.md §3` has been updated to reflect this
so the doc matches the code, not the other way around.

### A bug the test suite caught, in itself

Eloquent's own `Model::is()` treats two *unsaved* models of the same class as
"the same model," because both have a `null` primary key and `is()` compares
keys. That would have made two distinct in-memory plans that merely share a
price look like the same plan to the calculator's same-plan guard — exactly
the shape several of the rounding tests use (two plans, same price, testing
that rounding still bounds correctly). `ProrationCalculator::samePlan()`
therefore compares object identity first, and persisted primary keys second,
never treating two `null`-identity instances as equal just because neither
has been saved.

### A test-writing mistake, also caught by running the tests

Several of the first test assertions were arithmetically wrong, not the
calculator: `diffInDays('2026-09-15', '2026-10-01')` is 16 days, not the 15
a hand-count assumed, and `CarbonImmutable::diffInDays()` returns a `float`
(so `365.0` is not `toBe(365)` under strict comparison). Both were caught by
actually running the suite rather than trusting mental arithmetic, which is
the entire point of writing the tests first.

### How to see it working

```bash
docker exec billcycle-app-1 php artisan test --filter=Proration
```

Expect 16 passed, 1 todo, in well under a second of actual test execution
(the ~8–20s you'll see includes Laravel's framework bootstrap, which the
calculator itself never touches).

### What's left in Phase 2

- Wiring the calculator into a real plan-change service (Phase 3 territory —
  BUILD_PLAN.md scopes the calculator itself as "no database, no UI, no
  routes," and that boundary has been kept).
- The same-day-double-change limitation stays open and documented, per the
  plan — it is meant to ship as a known, named gap, not silently fixed on
  the side.

---

## How to keep this file useful

Update it at the end of each phase, in the same commit (or the next small
one) that finishes that phase's core work — not as a big rewrite at the end
of the project. If a decision needs the *why* spelled out in detail, put
that in DECISIONS.md and link to it from here; this file stays narrative and
skimmable.
