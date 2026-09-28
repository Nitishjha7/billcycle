# Deployment — Railway + Neon

Goal: **one public URL** that anyone can open, log into, and use without
installing anything.

> **Deployed (2026-09-28).** Live at
> [billcycle-production.up.railway.app](https://billcycle-production.up.railway.app).
> Everything below was executed through the Railway dashboard (GitHub-connected
> auto-deploy, not the `railway` CLI the steps below show — the CLI commands are
> kept as the equivalent reference, but the actual deploy was click-through plus
> Railway's in-browser Console for one-off commands). See "What actually
> happened" below for the real sequence — five real bugs were hit and fixed
> along the way, none of which were anticipated in the original plan.

## Locked plan (2026-09-27)

Deploy order across the portfolio: **cadence → billcycle (this app) →
webguard-scanpulse.** This app shares **one Railway project** with
webguard-scanpulse — one ~$5/month credit bucket instead of two separate
Railway billing relationships.

**Why this app is on Railway and not Cloud Run:** this app needs a
*standing* worker + scheduler process (`queue:work`, `schedule:work`)
running continuously — Cloud Run only runs containers in response to
requests, it has no standing-daemon mode. This app's billing/dunning job
needs the worker and scheduler actually running all the time, which is
what Railway gives for free as "just another service" (see "Why Railway,
not Render or Heroku" below for the full comparison).

**AWS was evaluated and rejected for this app.** A new AWS account (post
July-2025 restructuring) gets a $100-200 credit expiring in 6 months, not
an ongoing free tier, and the free-tier-eligible instance sizes (1GB RAM
on EC2 t3.micro, 0.5GB on Lightsail's cheapest plan) are tight for this
app's web+worker+scheduler+Redis stack. The AWS size that would run it
reliably (2-4GB RAM) costs $10-16/month — 2-3x this Railway baseline —
plus self-managed OS patches, Docker upkeep, and TLS renewal that Railway
handles automatically.

**Cost: ~$5/month Railway credit**, shared with webguard-scanpulse in the
same project — see the Cost section below. If combined usage from both
apps ever threatens to exceed the credit, take the less-critical link
down rather than risk a surprise bill.

---

## What is left

- [x] Create the Neon database (own project, not shared with cadence)
- [x] Create the Railway project and connect the repo — ended up as its
      **own** Railway project, not sharing one with webguard-scanpulse as
      originally planned (Railway's free-plan project-creation limit
      blocked adding webguard-scanpulse to this project — see
      webguard-scanpulse's own deployment doc for how that got resolved)
- [x] Set environment variables on all three services
- [x] Run migrations and the seeder against production
- [x] Verify the dashboard, customers list, and that billing:run is
      idempotent (took 6 calls to reach "0 generated" — see below)
- [ ] Put the live URL and demo login in the README (next)

---

## What actually happened

The plan above was directionally right, but five real, unanticipated
issues had to be fixed live before the app actually worked. Recorded here
because none of them were "read the docs harder" problems — each one was
a genuine gap between how the app was written for local Docker Compose
and what Railway's platform actually does differently.

**1. `DATABASE_URL` vs `DB_URL`.** Laravel's own convention for `config/database.php`'s
pgsql connection is `DB_URL`, not `DATABASE_URL` — but env vars were set
up as `DATABASE_URL` to match the naming convention used across the other
deployed projects (cadence, seatpulse). Without a fallback, the pgsql
config silently used its `127.0.0.1`/`5432` defaults and `migrate` failed
with "connection refused" instead of a clear "env var not found" error.
Fixed in `config/database.php`: `'url' => env('DB_URL', env('DATABASE_URL'))`.

**2. Neon's pooled connection and DDL transactions.** Even after DATABASE_URL
resolved correctly, `migrate --force` failed with
`SQLSTATE[25P02]: In failed sql transaction` on the very first migration
(`create_users_table`'s `ALTER TABLE ... ADD CONSTRAINT`). The pooled
connection string (PgBouncer, transaction-mode — the default Neon shows
first) doesn't reliably support DDL run inside Laravel's migration
transactions. Fixed by toggling **Connection pooling off** in Neon's
connection-details panel and using that (non-pooled, direct-to-Postgres)
string instead — cadence didn't hit this because Django's migration
transactions apparently don't trip the same PgBouncer edge case, so this
wasn't something the original plan could have anticipated from that
precedent.

**3. Seeding a full 50-customer/8-month history over the public internet is slow —**
really slow. Each of `DemoSeeder`'s many sequential DB round trips pays
real network latency against Neon from Railway, unlike local Docker
Compose where Postgres is on the same host. The first full-default attempt
ran 30+ minutes without finishing and had to be killed; a second attempt,
left running, eventually completed but wasn't retried given how long it
took. Fixed two ways: (a) made the seeder's scale configurable —
`SEED_CUSTOMER_COUNT` and `SEED_MONTHS` env vars, defaulting to the
original 50/8 so local development is unaffected — and (b) actually
seeded with `SEED_CUSTOMER_COUNT=10 SEED_MONTHS=2`, which completed in
under two minutes. A stray `Ctrl+C` during the first killed attempt didn't
actually kill the PHP process server-side (Railway's Console doesn't
reliably forward SIGINT), which caused a real `Deadlock detected` error
on the next `migrate:fresh` attempt — resolved by redeploying the service
(fresh container, no zombie process) rather than trying to kill it from
inside the same shell.

**4. nginx's public port.** Three separate, compounding issues, in the
order they were found and fixed:
   - nginx's config hardcoded `listen 80`, but Railway assigns a random
     port via `$PORT` and expects the container to listen there — first
     attempted fix was rendering the config from a template via
     `envsubst` at container start.
   - That fix hit an intermittent race: deploy logs showed the *same*
     container alternating between a working config and a literal,
     unsubstituted `${PORT}` across consecutive supervisord-triggered
     nginx restarts (supervisord restarts nginx directly on crash,
     bypassing the entrypoint script that ran `envsubst` — so a restart
     never got a chance to re-render). Moving the render into nginx's own
     `command=` (a `nginx-start.sh` wrapper invoked directly by
     supervisord, not the container-level entrypoint) made every restart,
     not just the container's first boot, regenerate the config — but the
     underlying `$PORT`-visibility race was never fully explained.
   - Given the fragility, the simplest fix won: **hardcoded nginx to
     listen on 8080** (dropping the `$PORT`/`envsubst` approach entirely)
     and pointed Railway's own per-service **Networking → Port** setting
     at 8080 to match — this is set in the Railway dashboard, not in
     code. Fully deterministic, no runtime substitution, no race.
     Also discovered along the way: Railway's auto-detected default for
     this service's public port was **9000** (php-fpm's port, picked up
     from `supervisord.conf` listing php-fpm before nginx) — not obviously
     wrong until you know nginx is what should be receiving the traffic.

**5. Two Laravel-specific gaps, found only once nginx actually worked:**
   - `Class "Redis" not found` on every request (session/cache/queue are
     all `redis`-backed) — the `phpredis` PHP extension (Laravel's default
     Redis client) was never installed. Fixed in the Dockerfile:
     `pecl install redis && docker-php-ext-enable redis`.
   - Mixed-content errors blocking every CSS/JS asset — Railway
     terminates TLS at its edge and forwards plain HTTP to the container,
     so Laravel saw every request as `http://` and generated `@vite()`
     asset URLs with that scheme, which browsers then block on a page
     actually served over `https://`. Fixed with `trustProxies()` in
     `bootstrap/app.php` (Laravel 11's config style — there's no
     `app/Http/Middleware/TrustProxies.php` to edit).
   - A `401` on `/api/user` after login succeeded (session cookie not
     being sent/accepted on the follow-up request) needed three more env
     vars not in the original plan: `SANCTUM_STATEFUL_DOMAINS` (the
     production domain — Sanctum won't treat cross-cookie requests as
     authenticated otherwise), `SESSION_SECURE_COOKIE=true` (the site is
     now `https://`), `SESSION_SAME_SITE=lax`.

**Idempotency, once the above were fixed, needed care to test correctly.**
The first `billing:run` on production reported "Generated 3 invoice(s)",
and running it again immediately reported the same — which looked like a
duplicate-invoicing bug. It wasn't: the seeded subscriptions' billing
periods only advanced through the seeded history's 2 simulated months
(`SEED_MONTHS=2`, per point 3 above), so by the real current date
(2026-09-28) they were roughly six months of billing cycles behind.
`billing:run` correctly advances one period per call — so it took **6
consecutive calls** before a due-subscription count of 0 was reached, and
only the 7th call (the true "run it twice" test) correctly reported
"Generated 0 invoice(s)". The unique constraint
(`invoices.subscription_id` + `period_start`) worked exactly as designed
throughout; the apparent bug was seeded data being older than expected
relative to the real deploy date, not a flaw in the billing logic.

---

## Why Railway, not Render or Heroku

This app needs **three processes**: web, a queue worker, and a scheduler. That is
the deciding constraint.

| | Railway | Render free | Heroku |
|---|---|---|---|
| Multiple processes | Yes, one service each | Free tier is web only | Yes, but no free tier |
| Sleeps when idle | No | **Yes, after 15 min** | n/a |
| Postgres | Add-on, or external | Free tier **expires after 90 days** | Paid |
| Cost | ~$5/month credit covers this | Free | From $7 |

**The sleeping is what rules out Render's free tier.** A 40-second cold start on
first click is a bad first impression, and a sleeping service means the
scheduler does not fire, so the nightly billing job never runs at all.

**Database goes on Neon, not Railway.** Free Postgres on most platforms expires —
Render's after 90 days — which would silently kill the live link months after
it was last looked at. Neon's free tier does not expire, and moving the database
off the app platform means the app can be redeployed or moved without touching it.

---

## Architecture

```
                    Railway project
   +-----------------------------------------------+
   |                                               |
   |   web         nginx + php-fpm    (public URL) |
   |   worker      queue:work                      |
   |   scheduler   schedule:work                   |
   |   redis       Railway plugin                  |
   |                                               |
   +-----------------------------------------------+
                          |
                          v
                    Neon Postgres
```

Three services from **one image**, differing only in their start command. Nothing
is built twice.

---

## Step 1 — Database (Neon)

1. [neon.tech](https://neon.tech) → new project → region closest to you
2. Copy the connection string. It must end with `?sslmode=require`

```
postgresql://user:pass@ep-xxx.ap-southeast-1.aws.neon.tech/billcycle?sslmode=require
```

Neon suspends compute when idle and wakes on the next query — a one-off delay of
about a second, invisible in a demo.

---

## Step 2 — Railway services

New project → Deploy from GitHub repo. Then add two more services **from the same
repo** and override the start command on each:

| Service | Start command |
|---|---|
| `web` | (default — nginx + php-fpm) |
| `worker` | `php artisan queue:work --tries=3 --timeout=90` |
| `scheduler` | `php artisan schedule:work` |

Add the **Redis** plugin. Railway injects `REDIS_URL` into every service in the
project.

### The scheduler is not optional

Without it `billing:run` never fires on its own, and the live demo becomes a
static dashboard. It is one container running `schedule:work`, and it is the
difference between a deployed app and a screenshot.

---

## Step 3 — Environment variables

Set on **all three** services:

```env
APP_KEY=base64:...          # php artisan key:generate --show
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billcycle.up.railway.app

DB_CONNECTION=pgsql
DATABASE_URL=postgresql://...?sslmode=require

QUEUE_CONNECTION=redis
REDIS_URL=${{Redis.REDIS_URL}}

PAYMENT_GATEWAY=fake
FAKE_GATEWAY_FAILURE_RATE=0

LOG_CHANNEL=stderr
SESSION_DRIVER=redis
CACHE_STORE=redis
```

**`APP_DEBUG=false` matters.** Laravel's debug page prints environment variables
on any unhandled exception — including the database URL. On a public link that is
a credential leak.

**`LOG_CHANNEL=stderr`**, not `stack`. Containers have no persistent disk; file
logs vanish on redeploy and fill the container in the meantime.

---

## Step 4 — Migrate and seed

```bash
railway run --service web php artisan migrate --force
railway run --service web php artisan db:seed --class=DemoSeeder --force
```

`--force` is required because both refuse to run in production without it. That
guard exists for good reason — here it is a demo database being deliberately
seeded.

### Reseeding later

```bash
railway run --service web php artisan migrate:fresh --seed --force
```

Destroys everything and rebuilds. Fine here, and worth doing periodically so
the dates in the seeded history stay recent rather than going stale.

> **Set a reminder to reseed.** Eight months of history seeded in September reads
> as stale by March, and stale dates are exactly the detail that makes a demo look
> abandoned.

---

## Step 5 — Verify

Not "the page loads". Verify the things that actually matter:

- [x] Login works (`admin@billcycle.demo` / `password`) and the dashboard
      loads
- [x] Customers list renders with seeded data
- [x] **`billing:run` is idempotent** — verified on production, though it
      took 6 calls to catch the seeded subscriptions' billing periods up
      to the real current date before a 7th call correctly reported "0
      generated" (see "What actually happened" above for why 3-then-3
      wasn't actually a duplicate-invoicing bug)
- [ ] A plan change shows the **proration preview** with correct arithmetic
- [ ] The dunning timeline renders on the past-due customer
- [ ] Invoice PDF downloads
- [ ] Scheduler logs show `billing:run` and `dunning:retry` firing on schedule

The unchecked items are the deeper walkthrough, not "does it boot" —
worth going through once before relying on this link in an interview.

---

## Migrations on deploy — deliberately not automatic

Many Laravel deploys run `migrate --force` in a release command. Not here.

With three services from one image, all three would run migrations on the same
deploy. Laravel's migration table prevents duplicate *application*, but three
processes racing on the same lock is a failure mode with no upside on a project
that deploys by hand a few times a month.

Migrations are run explicitly in Step 4. If this became a real deploy pipeline,
the right fix is a one-off release job — not a race.

---

## Cost

| | |
|---|---|
| Railway | ~$5/month credit; three small services and Redis fit inside it |
| Neon | Free tier, does not expire |

If the credit runs out, the honest fallback is to take the link down rather than
leave a dead URL in the README. A broken link reads worse than no link.

---

## Troubleshooting

**500 on every page** — `APP_KEY` missing. Generate with `php artisan key:generate
--show` and set it; it is not auto-generated in production.

**Database connection refused** — `?sslmode=require` missing from the Neon URL.
Neon requires TLS.

**Jobs queue but never run** — the worker service is not running, or `REDIS_URL`
is not set on it. Check the worker's own environment, not just `web`.

**Billing job never fires on its own** — the scheduler service is missing. This is
the most likely thing to be forgotten, because the app looks completely fine
without it.

**Assets 404** — `npm run build` did not run in the image, or `APP_URL` is wrong
and asset URLs point somewhere else.

---

## After deploying

- [x] Live URL + demo login at the top of the README
- [ ] Real screenshots in the README, replacing the ASCII sketches in
      [UI_FLOW.md](UI_FLOW.md)
- [ ] README test counts taken from the actual run, not estimated
