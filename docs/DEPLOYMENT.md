# Deployment — Railway + Neon

Goal: **one public URL** in the README that an interviewer can click, log into,
and use — without installing anything.

> Nothing is deployed yet. This is written ahead of Phase 6 so the decisions are
> made before the pressure of "just get it online". It will be corrected against
> reality once the first deploy happens.

---

## What is left

- [ ] Create the Neon database (Step 1)
- [ ] Create the Railway project and connect the repo (Step 2)
- [ ] Set environment variables (Step 3)
- [ ] Run migrations and the seeder against production (Step 4)
- [ ] Verify the five screens and the idempotency demo (Step 5)
- [ ] Put the live URL and demo login in the README

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

**The sleeping is what rules out Render's free tier.** An interviewer clicking a
link and waiting 40 seconds for a cold start has already formed an opinion. And a
sleeping service means the scheduler does not fire, so the nightly billing job
never runs — which is the one thing this project exists to demonstrate.

**Database goes on Neon, not Railway.** Free Postgres on most platforms expires —
Render's after 90 days — which would silently kill the portfolio link months after
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

Destroys everything and rebuilds. Fine here, and worth doing before an interview
so the dates in the seeded history are recent rather than months stale.

> **Set a reminder to reseed.** Eight months of history seeded in September reads
> as stale by March, and stale dates are exactly the detail that makes a demo look
> abandoned.

---

## Step 5 — Verify

Not "the page loads". Verify the things the demo depends on:

- [ ] Dashboard shows seeded numbers — MRR non-round, some suspended
- [ ] A plan change shows the **proration preview** with correct arithmetic
- [ ] The dunning timeline renders on the past-due customer
- [ ] Invoice PDF downloads
- [ ] **`billing:run` twice produces one invoice** — the core demo, on production:

```bash
railway run --service web php artisan billing:run
railway run --service web php artisan billing:run   # must report 0 generated
```

- [ ] Worker logs show payment attempt jobs being processed
- [ ] Scheduler logs show the daily tick

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
leave a dead URL in the README. **A broken demo link is worse than no link** — it
reads as a project that was abandoned.

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

- [ ] Live URL + demo login at the top of the README
- [ ] **Real screenshots** replacing the ASCII sketches in [UI_FLOW.md](UI_FLOW.md)
- [ ] Remove the "not built yet" banner from the README
- [ ] README test counts taken from the actual run, not estimated
- [ ] Add a `DECISIONS.md` entry for anything that surprised you here — deployment
      surprises are good interview material precisely because they are specific
