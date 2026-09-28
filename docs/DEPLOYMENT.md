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
2. Open the project → **Connect** (top of the sidebar) → **Connection
   Details**
3. **Turn "Connection pooling" off** before copying anything — see "What
   actually happened" above (point 2): the pooled string is the default
   shown, and it rejects the DDL transactions `migrate` runs. This has to
   be the non-pooled string from the start, not fixed after a failed
   migration.
4. **Show password**, then **Copy snippet**. It must end with
   `?sslmode=require` (Neon adds this automatically):

```
postgresql://user:pass@ep-xxx.ap-southeast-1.aws.neon.tech/neondb?sslmode=require
```

Neon suspends compute when idle and wakes on the next query — a one-off delay of
about a second, invisible in a demo.

---

## Step 2 — Railway services

1. [railway.app](https://railway.app) → **New Project** → **Deploy from
   GitHub repo** → select `billcycle`. This creates the first service
   (named after the repo, `billcycle`) — it becomes `web` by default,
   since the Dockerfile's own `CMD` runs supervisord (nginx + php-fpm).
2. On the canvas, **"+ New" → "GitHub Repo" → `billcycle`** again — this
   adds a second service **from the same repo**, defaulting to some
   auto-generated name (e.g. "charismatic-education"). Rename it to
   `worker` in its Settings, then in **Settings → Deploy → Custom Start
   Command**, enter `php artisan queue:work --tries=3 --timeout=90` and
   confirm with the checkmark next to the field — this doesn't take
   effect until you also click through to that service's own **Deploy**
   button afterward.
3. Repeat step 2 once more for `scheduler`, with start command
   `php artisan schedule:work`.
4. **"+ New" → "Database" → "Add Redis"** — adds a fourth service, a
   managed Redis plugin. No configuration needed; every other service in
   the project can reference it as `${{Redis.REDIS_URL}}`.

Note: the original plan was to put this app in the **same** Railway
project as webguard-scanpulse, sharing one credit bucket. In practice,
Railway's free-plan project-creation limit blocked adding a fourth
service *or* a new project once this one already existed — webguard-scanpulse
ended up in its own separate Railway project instead. See its own
deployment doc for how that was resolved.

| Service | Start command |
|---|---|
| `web` (named `billcycle`) | (default — nginx + php-fpm via supervisord) |
| `worker` | `php artisan queue:work --tries=3 --timeout=90` |
| `scheduler` | `php artisan schedule:work` |
| `Redis` | Railway plugin, no start command |

### The scheduler is not optional

Without it `billing:run` never fires on its own, and the live demo becomes a
static dashboard. It is one container running `schedule:work`, and it is the
difference between a deployed app and a screenshot.

---

## Step 3 — Environment variables

Set on **all three** services (`web`/`billcycle`, `worker`, `scheduler` —
identical values on each, one at a time via each service's Variables tab):

```env
APP_KEY=base64:...          # openssl rand -base64 32, prefixed "base64:"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billcycle-production.up.railway.app

DB_CONNECTION=pgsql
DATABASE_URL=postgresql://...?sslmode=require   # the NON-POOLED string, Step 1

QUEUE_CONNECTION=redis
REDIS_URL=${{Redis.REDIS_URL}}

PAYMENT_GATEWAY=fake
FAKE_GATEWAY_FAILURE_RATE=0

LOG_CHANNEL=stderr
SESSION_DRIVER=redis
CACHE_STORE=redis

# Added after the fact -- see "What actually happened", point 5. Not
# obvious from a local-only Docker Compose setup, where the frontend and
# API sharing one origin means none of this comes up.
SANCTUM_STATEFUL_DOMAINS=billcycle-production.up.railway.app
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
```

**`APP_DEBUG=false` matters.** Laravel's debug page prints environment variables
on any unhandled exception — including the database URL. On a public link that is
a credential leak.

**`LOG_CHANNEL=stderr`**, not `stack`. Containers have no persistent disk; file
logs vanish on redeploy and fill the container in the meantime.

**`APP_URL` can only be filled in accurately after Step 2's `web` service has a
domain.** Settings → Networking → **Generate Domain** on the `web`/`billcycle`
service produces the `*.up.railway.app` URL — copy it into `APP_URL` (and the
three Sanctum/session vars above) only once it exists, same ordering problem as
cadence's `ALLOWED_HOSTS` on Cloud Run.

**Also in that same Networking panel: check the auto-detected Port.**
Railway guessed **9000** here (php-fpm's port, picked up from
`supervisord.conf` listing php-fpm before nginx) instead of the **8080**
nginx actually listens on (see "What actually happened", point 4) — wrong
until you notice nginx, not php-fpm, is what's supposed to receive
traffic. Set it to 8080 by hand.

---

## Step 4 — Migrate and seed

Done via Railway's in-browser **Console** tab on the `web`/`billcycle`
service (an SSH-in-browser shell into the running container) rather than
the `railway` CLI shown below — no local CLI install needed. The
equivalent CLI commands:

```bash
railway run --service web php artisan migrate --force
railway run --service web php artisan db:seed --class=DemoSeeder --force
```

`--force` is required because both refuse to run in production without it. That
guard exists for good reason — here it is a demo database being deliberately
seeded.

**What was actually run, in order:**

```bash
php artisan migrate --force
# first attempt failed here -- SQLSTATE[25P02], the pooled-connection
# issue (Step 1). Switched DATABASE_URL to the non-pooled string, then:
php artisan migrate:fresh --force
# succeeded

# Full-default seeding (50 customers, 8 months) was killed after 30+
# minutes without finishing -- too slow over Neon's network latency from
# Railway. Re-ran at a much smaller scale instead:
SEED_CUSTOMER_COUNT=10 SEED_MONTHS=2 php artisan db:seed --class=DemoSeeder --force
# completed in under 2 minutes
```

`SEED_CUSTOMER_COUNT` and `SEED_MONTHS` are env vars `DemoSeeder` reads
(added specifically for this deploy — see "What actually happened", point
3); they default to the original 50/8 spec, so local development and CI
are unaffected. Only pass them inline on the seed command itself, not as
persistent Railway service variables — they're a one-off scale knob, not
part of the app's real configuration.

### Reseeding later

```bash
php artisan migrate:fresh --force
SEED_CUSTOMER_COUNT=10 SEED_MONTHS=2 php artisan db:seed --class=DemoSeeder --force
```

Destroys everything and rebuilds, at the same reduced scale that
actually finishes in reasonable time against Neon from Railway.

> **Set a reminder to reseed.** Even two months of history seeded from
> "day 1" reads as stale within a few weeks of the real date drifting past
> it — the demo's `billing:run` walkthrough (Step 5) depends on the
> seeded subscriptions not already being wildly overdue, which gets worse
> the longer the seed data sits untouched. Reseeding also resets
> `billing:run`'s catch-up count back to a small, demo-friendly number of
> calls rather than an ever-growing one.

---

## Step 5 — Verify

Not "the page loads". Verify the things that actually matter.

- [x] Login works (`admin@billcycle.demo` / `password`) and the dashboard
      loads
- [x] Customers list renders with seeded data
- [x] **`billing:run` is idempotent** — see the exact test sequence below
- [ ] A plan change shows the **proration preview** with correct arithmetic
- [ ] The dunning timeline renders on the past-due customer
- [ ] Invoice PDF downloads
- [ ] Scheduler logs show `billing:run` and `dunning:retry` firing on schedule

The unchecked items are the deeper walkthrough, not "does it boot" —
worth going through once before relying on this link in an interview.

### How `billing:run` idempotency was actually verified

The naive test — run it, run it again, expect the second run to report 0
— *failed* the first time, and looked like a duplicate-invoicing bug:
both calls reported `Generated 3 invoice(s)`. It wasn't a bug. Here's the
full sequence that established what was actually going on, useful as a
template for re-verifying after any reseed:

```bash
# 1. How many invoices exist right now (baseline)?
php artisan tinker --execute="echo App\Models\Invoice::count();"

# 2. Run it once
php artisan billing:run
# → Generated 3 invoice(s). 0 already billed this cycle. 0 skipped.

# 3. Run it again immediately
php artisan billing:run
# → Generated 3 invoice(s). 0 already billed this cycle. 0 skipped.
#   (looks like duplicate billing -- it isn't, see step 4)

# 4. Check whether the *same* subscriptions are still "due" --
#    if this is 0, something else is wrong (a real bug); if it's > 0,
#    the seeded data is just further behind the real date than expected
php artisan tinker --execute="echo App\Models\Subscription::whereIn('status',['active','past_due'])->where('current_period_end','<=',now())->count();"
# → 3  (still due -- confirms this is catch-up billing, not a duplicate)

# 5. Inspect one subscription's period directly, to see it actually
#    advancing across calls rather than staying frozen
php artisan tinker --execute="echo App\Models\Subscription::whereIn('status',['active','past_due'])->first()->current_period_end;"
# → advanced by one billing interval each time billing:run was called

# 6. Keep calling billing:run until the due count from step 4 reaches 0
#    (took 6 total calls here, since the seeded history was ~6 months
#    behind the real deploy date -- billing:run only advances one period
#    per subscription per call, by design)
php artisan billing:run   # × N, until "Generated 0 invoice(s)."

# 7. THE actual idempotency test: call it one more time once step 6
#    reaches 0 generated. This must also report 0.
php artisan billing:run
# → Generated 0 invoice(s). 0 already billed this cycle. 0 skipped.
```

The lesson for next time: **check the due count (step 4) before
concluding "generated > 0 twice" is a bug.** `billing:run`'s unique
constraint (`invoices.subscription_id` + `period_start`) makes genuine
double-billing structurally impossible — repeated non-zero results mean
the seeded data is behind the clock, not that the guarantee failed.

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
