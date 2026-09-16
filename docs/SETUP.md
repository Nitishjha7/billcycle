# Setup

> Nothing is built yet. This is the intended setup, written ahead of the code so
> Phase 1 has a target. It will be verified and corrected once the code exists.

---

## Requirements

- Docker + Docker Compose
- PHP 8.3 and Composer (only if running outside Docker)

---

## Quick start

```bash
git clone <repo>
cd billcycle

cp .env.example .env
php -r "echo 'APP_KEY=base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
# paste the output into .env

docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=DemoSeeder
```

| | |
|---|---|
| App | http://localhost:8004 |
| Horizon | http://localhost:8004/horizon |
| Postgres | localhost:5436 |

Demo login: `admin@billcycle.demo` / `password`

Ports are offset from the other projects in the portfolio so several can run at
once.

---

## Services

| Service | Purpose |
|---|---|
| `app` | Laravel + PHP-FPM |
| `nginx` | Web server |
| `postgres` | PostgreSQL 16 |
| `redis` | Queue backend |
| `worker` | Queue worker (payment attempts, retries) |
| `scheduler` | Runs `schedule:work` — fires `billing:run` daily |

---

## Commands

```bash
# Billing
php artisan billing:run              # generate due invoices
php artisan billing:run --dry-run    # show what would be billed

# Demo data
php artisan db:seed --class=DemoSeeder
php artisan db:seed --class=DemoSeeder --profile=messy   # more unhealthy accounts

# Tests
php artisan test
php artisan test --filter=Proration
php artisan test --parallel
```

---

## Environment

```env
APP_KEY=base64:...

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=billcycle

QUEUE_CONNECTION=redis
REDIS_HOST=redis

# Fake gateway behaviour — no real payment provider is used
PAYMENT_GATEWAY=fake
FAKE_GATEWAY_FAILURE_RATE=0
```

`FAKE_GATEWAY_FAILURE_RATE` drives random failures for demo purposes. Tests set
gateway behaviour explicitly rather than relying on it.

---

## Notes

- There is **no real payment provider**. `PAYMENT_GATEWAY=fake` is the only
  supported value; the reason is in [TECHNICAL_SPEC.md](TECHNICAL_SPEC.md) §6.
- The scheduler container must be running for `billing:run` to fire on its own.
  For demos, run the command by hand — that is the point of the idempotency
  moment in [DEMO_SCRIPT.md](DEMO_SCRIPT.md).
