# Decisions

A running log of every non-obvious decision, **written at the time it is made**.

## Why this file exists

An interviewer will not read the code. They will point at something and ask "why
is it like that?". This file is where that answer lives while it is still fresh.

Written as you go, it is an honest record of the reasoning. Written afterwards
from memory, it is a reconstruction — and it reads like one.

## How to write an entry

Three to six lines. Date, what was chosen, what was rejected, and **why**.

The "what was rejected" line matters most. A decision with no alternative
considered is not a decision, it is a default.

```markdown
## YYYY-MM-DD — Short title

What was chosen. What the alternative was. Why the alternative loses. What it
costs (every decision costs something).
```

Also log **bugs that surprised you**. Those are the strongest interview material
available, and they are forgotten within a week if not written down.

---

## 2026-09-16 — Why this project at all

Portfolio was entirely Python — Flask, FastAPI, agentic work. Applying for Laravel
roles with no Laravel project is a screening problem before it is anything else.

Billing chosen over a CRUD app because it has a real correctness problem at the
centre — proration arithmetic, idempotent jobs — and because queues, scheduled
jobs and server-rendered dashboards are what Laravel is actually used for.

Rejected: a helpdesk/ticketing system (too close to an existing portfolio project
in name), and an ERP-shaped "business OS" (scope always expands, and a half-built
ERP demos badly).

---

## 2026-09-17 — composer.lock silently drifted to PHP 8.4-only packages

`composer create-project` was run inside the official `composer:2.8` Docker
image, which bundles whatever PHP the image ships internally -- not the PHP
8.3 the app actually runs on. The resulting `composer.lock` locked
`symfony/clock`, `nesbot/carbon` and friends to versions requiring PHP
>=8.4.1, which only surfaced later as a `composer install` failure inside the
real `php:8.3-fpm` app image.

Fixed by re-running `composer update` inside a container built from
`php:8.3-cli` directly, so the lockfile is resolved against the same PHP
version the app runs on. Lesson: **always generate `composer.lock` inside (or
against) the exact runtime image**, never a generic tool image -- a lockfile
that installs is not the same as a lockfile that installs on *your* PHP.

---

## 2026-09-19 — Real container env vars were silently overriding phpunit.xml's test database

`docker-compose.yml` passes `.env` to the `app` container via `env_file:`, so
`DB_CONNECTION=pgsql` exists as a real process environment variable inside
it. PHP's `getenv()`/`$_ENV` treat a real environment variable as
higher-priority than `phpunit.xml`'s `<env>` block, so every `php artisan
test` run was quietly hitting real Postgres over the network instead of the
fast in-memory SQLite the test suite was written for. The tests still
passed, just ~30-70x slower than they should have been (a Feature test
suite of ~30s instead of ~1s) -- nothing failed loudly, which is exactly why
this kind of drift is dangerous.

Fixed by adding `force="true"` to each of the `DB_*` `<env>` entries in
`phpunit.xml`, which tells Laravel's test bootstrapping to override the real
environment variable rather than defer to it. Confirmed by the SmokeTest's
duration dropping from ~22s to ~0.3s once fixed -- a proxy for "is this
suite actually hitting SQLite," not just "does it pass."

**Lesson:** a green test suite is not proof it is testing what you think it
is testing. When Docker Compose injects the same env vars into both the dev
container and the test run, they need to disagree somewhere, and
`phpunit.xml`'s `force="true"` is that seam for Laravel specifically.

---

## 2026-09-24 — Suspended subscriptions need retries with no scheduled next_retry_at

The dunning retry schedule (TECHNICAL_SPEC.md §5) ends at attempt 4: on
failure, `next_retry_at` is left `null` and the subscription is suspended.
That's correct for the schedule itself, but it left `DunningRetryRunner`
with no way to ever pick a suspended subscription's invoice back up --
querying only "attempts with a due `next_retry_at`" means a suspended
subscription's last attempt has none, so it can never be found again.

Fixed by having the retry runner also pick up every `open` invoice
belonging to a `suspended` subscription, every run, regardless of
`next_retry_at`. Rejected: a separate "customer requests reactivation"
endpoint as the only path in — more realistic for a real gateway (which
would reactivate via webhook after a card update), but out of scope for a
project with no real payment UI yet, and the spec's own words ("a single
successful payment" can revive it) don't require anything more specific
than "the system keeps trying."

## 2026-09-24 — Same bug shape as before: diffInDays returns a float

`$attempt->attempted_at->diffInDays($attempt->next_retry_at)` returned
`1.0`, not `1`, so `toBe(1)` failed under Pest's strict comparison -- the
exact same trap as the proration test suite in Phase 2. Cast to `(int)` at
the assertion site. Worth a general habit note: any `Carbon::diffIn*()`
result being compared with `toBe()` (not `toEqual()`) needs an explicit
cast, in this codebase specifically.

---

## Entries from here are written as the code is built

Things that will need an entry:

- Rounding direction, once the first uneven division shows up
- Whether the unique constraint alone is enough, or the job needs an advisory lock
- What happens to a subscription cancelled mid-dunning
- Any bug that took more than an hour — especially the ones that were not the
  obvious cause
