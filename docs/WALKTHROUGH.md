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
| 3 — Invoices & billing job | ✅ Done | `billing:run` is idempotent, gapless numbering, plan changes wired to the calculator |
| 4 — Gateway & dunning | ✅ Done | Fake gateway, retry schedule, state machine, 500-event chaos test |
| 5 — UI & seeder | ✅ Done | Five screens, login, DemoSeeder with real 8-month history |
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
  routes," and that boundary has been kept). **Done in Phase 3** — see below.
- The same-day-double-change limitation stays open and documented, per the
  plan — it is meant to ship as a known, named gap, not silently fixed on
  the side.

---

## Phase 3 — Invoices and the billing job

**Goal:** `php artisan billing:run` works and cannot double-charge.

### What was built

- `app/Billing/InvoiceNumberGenerator.php` — the gapless `INV-YYYY-NNNNNN`
  scheme from [TECHNICAL_SPEC.md §7](TECHNICAL_SPEC.md#7-invoice-numbering):
  one row per year in a new `invoice_sequences` table, read with
  `lockForUpdate()` inside the same transaction that creates the invoice.
  The row lock serialises allocation; the shared transaction means a
  rollback un-allocates the number instead of leaving a gap.
- `app/Billing/BillingRunner.php` — the core of `php artisan billing:run`.
  Selects subscriptions that are `active`/`past_due` and due (their
  `current_period_end` has passed), then per subscription: re-checks status
  inside a transaction (a customer may cancel between selection and
  processing), creates the next-period invoice and its subscription line,
  advances the period, and commits. A second attempt at the same
  `(subscription_id, period_start)` hits the `UNIQUE` constraint from Phase
  1 and is counted as "already billed" rather than retried — no
  `SELECT ... IF NOT EXISTS` race window, exactly as
  [TECHNICAL_SPEC.md §4](TECHNICAL_SPEC.md#4-the-billing-job-and-idempotency)
  specifies.
- `app/Console/Commands/BillingRun.php` — the `billing:run` artisan command,
  scheduled daily via `routes/console.php`.
- Invoice integrity guards on the `Invoice` model: once `status` is `paid`,
  any further save throws — except `void()`, which flips status without
  touching amounts and never deletes the row.
- `app/Billing/PlanChangeService.php` — wires `ProrationCalculator` into a
  real plan change. `preview()` and `apply()` call the calculator with
  identical inputs (same subscription, same target plan, same change date),
  so the confirmation screen a customer sees and the invoice they actually
  get can never disagree — see
  [UI_FLOW.md §4](UI_FLOW.md#4-plan-change--the-proration-preview). An
  upgrade (net positive) creates and charges an invoice *today*, covering
  only the remainder of the current cycle; a downgrade charges nothing
  today — no refund, credit only, per the deliberate limitation in
  TECHNICAL_SPEC.md §3.
- 23 new tests across `tests/Feature/BillingRunTest.php` (10, matching
  [TEST_PLAN.md §2](TEST_PLAN.md#2-billing-job-and-idempotency--12-tests)),
  `tests/Feature/InvoiceTest.php` (9, matching
  [TEST_PLAN.md §5](TEST_PLAN.md#5-invoice-integrity--8-tests)), and
  `tests/Feature/PlanChangeTest.php` (4). Full suite: 40 passed, 1 documented
  `todo`.

### Two bugs the tests caught

**A silent off-by-one in the due-subscription query.** `BillingRunner`
originally compared the due date as
`current_period_end <= $now->toDateString()` — a full `datetime` column
against a bare date string. `'2026-02-01 00:00:00' <= '2026-02-01'` is
*false* lexically, so a subscription due exactly today was silently
skipped every time; the twelve-month test caught this immediately by coming
back with 11 invoices instead of 12. Fixed by comparing against
`$now->endOfDay()` instead of a bare date string.

**A test infrastructure bug, not a code bug, but a more consequential one.**
`docker-compose.yml` passes `.env` into the `app` container via `env_file:`,
so `DB_CONNECTION=pgsql` exists as a *real* process environment variable
inside it — and PHP's `getenv()` treats a real env var as higher priority
than `phpunit.xml`'s `<env>` block. Every `php artisan test` run had
therefore been quietly hitting real Postgres over the network the whole
time, not the fast in-memory SQLite the suite was written for. Nothing
failed — the tests were still correct, just 30–70× slower than intended
(a run that should take ~1s was taking ~25s), which is exactly the kind of
drift that goes unnoticed because green is green. Caught by the timings
looking wrong, not by an assertion failing. Fixed with `force="true"` on
each `DB_*` entry in `phpunit.xml`, which tells Laravel's test bootstrap to
win that fight. Full writeup in
[DECISIONS.md](../DECISIONS.md#2026-09-19--real-container-env-vars-were-silently-overriding-phpunitxmls-test-database).

### How to see it working

```bash
docker exec billcycle-app-1 php artisan billing:run
docker exec billcycle-app-1 php artisan billing:run   # second run: "0 invoices, N already billed"
docker exec billcycle-app-1 php artisan test --filter=BillingRun
docker exec billcycle-app-1 php artisan test --filter=Invoice
docker exec billcycle-app-1 php artisan test --filter=PlanChange
```

### Closing the loop: downgrade credit now lands on the next invoice

A downgrade's credit was initially only ever recorded on `PlanChange` — real
money the customer was owed, with nothing that actually gave it back. Closed
by adding `plan_changes.applied_invoice_id` (nullable, set once) and having
`BillingRunner` look up any unclaimed downgrade credit for the subscription
(`net_paise <= 0`, `applied_invoice_id` still null) when it creates that
subscription's next invoice, add it as a negative `proration_credit` line,
and mark the `PlanChange` as applied so the same credit can never be carried
onto two invoices. `lockForUpdate()` on that lookup keeps it safe if
`billing:run` ever runs concurrently for the same subscription, same as the
invoice-numbering lock.

### What's left in Phase 3

- `billing:run --dry-run` is currently a stub that prints a warning and does
  nothing — a real preview (what *would* be billed, without writing) still
  needs implementing.

---

## Phase 4 — Gateway and dunning

**Goal:** a failed payment walks the full retry schedule and suspends; a
later payment can bring it back.

### What was built

- `app/Billing/PaymentGateway.php`, `GatewayResult.php` — the seam a real
  gateway would slot into. Two implementations, per
  [TECHNICAL_SPEC.md §6](TECHNICAL_SPEC.md#6-the-fake-payment-gateway):
  `NullGateway` (always succeeds) and `FakeGateway` (outcome entirely
  configuration-driven — `alwaysFails()`, `failReference()`,
  `failNextAttempts()`, keyed by charge reference so independent invoices in
  the same test don't interfere with each other).
- `app/Billing/SubscriptionStateMachine.php` — the dunning transitions as an
  explicit allow-list (`active → past_due → suspended → active`, plus
  `cancelled` from anywhere still active). Anything not on the list throws
  `LogicException` rather than silently no-opping — "illegal transitions
  throw," per the spec, not "illegal transitions are avoided by convention."
- `app/Billing/DunningService.php` — attempts a single invoice's payment.
  Success: records the `Payment`, marks the invoice `paid`, resets the
  subscription to `active`; if it had been suspended, restarts the period
  from the payment date rather than billing for the suspended days (the
  policy decision TECHNICAL_SPEC.md §5 answers explicitly). Failure: records
  the `PaymentAttempt` with its failure code, schedules the next retry at
  +1/+3/+5 days, and moves the subscription to `past_due` — or `suspended`
  once attempt 4 also fails.
- `app/Billing/DunningRetryRunner.php` (`php artisan dunning:retry`,
  scheduled hourly) — finds invoices due for another attempt. Two cases:
  a scheduled retry whose `next_retry_at` has passed, and every open invoice
  belonging to a currently-`suspended` subscription (see the bug note
  below for why the second case exists at all).
- `BillingRunner` now dispatches the first payment attempt right after its
  own transaction commits, deliberately not nested inside it — a gateway
  failure must never roll back the invoice that was just correctly created.
- `tests/Feature/DunningTest.php` — 11 tests from
  [TEST_PLAN.md §3](TEST_PLAN.md#3-dunning--15-tests): the four-failures-to-
  suspended headline test, recovery at every stage, the state machine's
  illegal-transition guard, the exact retry intervals, and both failure
  codes following the same schedule.
- `tests/Feature/ChaosTest.php` — the highest-value test in the project.
  500 random events (`upgrade`, `downgrade`, `cancel`, `pay`, `fail_payment`,
  `advance_clock`) fired at 20 customers in a seeded random sequence, then
  one assertion per customer: `invoicedTotalPaise() - paidTotalPaise() ==
  outstandingPaise()`. It passed on the first real run. Backing it,
  `Customer::invoicedTotalPaise()` / `paidTotalPaise()` / `outstandingPaise()`
  were added as the three ledger queries the invariant is built from.

### A real gap the retry schedule left open

The retry schedule ends at attempt 4 with `next_retry_at` left `null` — by
design, there's nothing more scheduled. But that meant `DunningRetryRunner`,
querying only "attempts with a due `next_retry_at`," could **never find a
suspended subscription's invoice again** — nothing was ever going to make
`next_retry_at` non-null after suspension. That directly contradicts
TECHNICAL_SPEC.md §5's own rule: "suspension is not cancellation... can be
revived by a single successful payment." Fixed by having the retry runner
also pick up every open invoice of a suspended subscription on every run,
regardless of `next_retry_at`. Full reasoning in
[DECISIONS.md](../DECISIONS.md#2026-09-24--suspended-subscriptions-need-retries-with-no-scheduled-next_retry_at).

### How to see it working

```bash
docker exec billcycle-app-1 php artisan test --filter=Dunning
docker exec billcycle-app-1 php artisan test --filter=Chaos
```

### What's left in Phase 4

- Nothing scoped by BUILD_PLAN.md remains open. `billing:run --dry-run`
  (noted under Phase 3) is the one stub still outstanding project-wide.

---

## Phase 5 — UI and seeder

**Goal:** the demo in [DEMO_SCRIPT.md](DEMO_SCRIPT.md) can be performed end
to end.

### What was built

- All five screens from
  [UI_FLOW.md](UI_FLOW.md#the-five-screens): dashboard (`/`), customer list
  (`/customers`), customer detail with the dunning timeline
  (`/customers/{id}`), the plan-change proration preview
  (`/customers/{id}/change-plan`), and invoice detail (`/invoices/{id}`).
  Blade + Tailwind, server-rendered, Tailwind defaults only — no custom
  design system, per the doc's own design rule.
- A minimal login (`/login`), added even though UI_FLOW.md's five screens
  don't include one — [SETUP.md](SETUP.md) promises a demo login
  (`admin@billcycle.demo` / `password`), and this is the smallest thing that
  makes that true without adding scope beyond it: one seeded admin user, no
  registration, no password reset.
- The dashboard's "recent activity" feed is assembled by merging and
  sorting rows from `invoices`, `payment_attempts`, `plan_changes` and
  `subscriptions` — there is no dedicated activity-log table, because the
  schema is deliberately fixed at eight tables
  ([TECHNICAL_SPEC.md §2](TECHNICAL_SPEC.md#2-schema)).
- The plan-change screen's controller calls `PlanChangeService::preview()`
  and `apply()` with identical inputs for the same request, which is what
  makes the confirmation box and the real charge structurally unable to
  disagree — the same guarantee the pure `ProrationCalculator` gives at the
  unit level now holds at the HTTP level too.
- `database/seeders/DemoSeeder.php` — the single most important piece of
  demo infrastructure in the project, per UI_FLOW.md's own words. It does
  **not** fabricate rows: it drives the real `BillingRunner`,
  `PlanChangeService` and `DunningRetryRunner` forward through eight
  simulated months for 50 customers, the same engines the test suite
  exercises. That is what makes the seeded invoice numbers genuinely
  sequential and gapless (verified: 379 invoices numbered 1–379, no gaps)
  and every amount a real system output — including a real
  `Rs 967.75` proration credit line from an actual simulated plan change,
  not a hand-picked "looks realistic" number.
- Three subscriptions are deliberately reserved and driven individually so
  every status UI_FLOW.md asks for actually appears in the seed:
  `deepInDunning` (3 failed attempts, ends suspended — the dunning timeline
  screen's reason for existing), `recovered` (fails twice, then pays, back
  to active — proves the reset path), and `stuckInRetry` (fails once on the
  very last simulated month and is deliberately left mid-schedule, so
  `past_due` — a real status — isn't just theoretically possible but
  actually present in the data).
- A multi-stage `Dockerfile`: a `node:20-alpine` stage runs `npm install`
  and `vite build`, and only `public/build` is copied into the PHP image —
  so `docker compose up --build` produces working CSS/JS without a manual
  host-side `npm run build` step.

### Two infrastructure bugs, not logic bugs

**The container silently couldn't write anywhere in `storage/`.** The
Dockerfile's `chown -R www-data:www-data storage bootstrap/cache` ran at
*build* time, but `docker-compose.yml`'s bind mount (`.:/var/www/html`)
replaces the image's baked-in ownership with the host filesystem's ownership
the moment the container actually *starts*. PHP-FPM runs as `www-data`, so
every request that touched a session, a compiled view, or a log file failed
with a bare `tempnam(): file created in the system's temporary directory`
500 — no stack trace, nothing useful in `laravel.log`, because the failure
happened before Laravel's own error handling could engage. Fixed with
`docker/entrypoint.sh`, which re-applies the `chown` on every container
*start*, not just at build time.

**The seeder's first attempt produced zero failed payments at all,** which
meant `past_due` and `suspended` never appeared no matter how "unhealthy"
the configured share was. Cause: `BillingRunner` dispatches a subscription's
*first* payment attempt itself, immediately after that subscription's
invoice commits, still inside the same `billing:run` call. The seeder was
arming `FakeGateway` to fail *after* calling `billing:run` for the batch —
by then, every invoice's first attempt had already happened and already
succeeded. Fixed by billing each intentionally-failing subscription through
its own isolated `billing:run` call, with the gateway pre-armed to fail
before that specific call, rather than batching everyone through one call
with the gateway configured afterward.

### How to see it working

```bash
docker compose up -d --build
docker exec billcycle-app-1 php artisan migrate --force
docker exec billcycle-app-1 php artisan db:seed --force
```

Then visit `http://localhost:8004/login` and sign in as
`admin@billcycle.demo` / `password`.

### What's left in Phase 5

- Nothing. PDF invoice generation (`barryvdh/laravel-dompdf`, a plain-HTML
  template separate from the Tailwind one since dompdf's CSS support is
  limited) closed the one remaining BUILD_PLAN.md checklist item — every
  Phase 5 item is now done.

---

## How to keep this file useful

Update it at the end of each phase, in the same commit (or the next small
one) that finishes that phase's core work — not as a big rewrite at the end
of the project. If a decision needs the *why* spelled out in detail, put
that in DECISIONS.md and link to it from here; this file stays narrative and
skimmable.
