# Development guide

This document is for **contributors** who have cloned the repository from git. It is not an end-user installation path — to run nrth on a server, use **[INSTALL.md](INSTALL.md)** (`scripts/install.sh`).

## Requirements

- PHP 8.3+ (8.4+ recommended; Docker image uses 8.4)
- Composer 2.7+
- Node.js 20.19+ or 22.12+
- PostgreSQL and Redis for full parity — or use Docker Compose

## Local setup (PHP on the host)

For contributors hacking on the codebase without Docker:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm install
npm run dev
```

In another terminal:

```bash
php artisan serve
```

Set `APP_URL=https://localhost:8000` in `.env`. For local development without a reverse proxy, set `APP_ALLOW_HTTP=true` so the app accepts plain HTTP. **Never use `APP_ALLOW_HTTP=true` on a server others can reach** — see [SELF_HOST.md](SELF_HOST.md).

### SQLite (minimal)

The default `.env.example` uses SQLite for quick experiments. For features that need Postgres/Redis/queues, use Docker Compose instead.

### Tests

```bash
php artisan test
```

Vite is stubbed in `tests/TestCase.php` — a production asset build is not required for PHPUnit.

### Code style

```bash
./vendor/bin/pint
```

## Docker Compose (full stack while developing)

If you need Postgres, Redis, and queues while working on a clone, you can use the same `compose.yaml` as production — but **new installs should use `scripts/install.sh`**, not manual `docker compose` steps.

After cloning:

```bash
./scripts/install.sh --dev
```

Or, if you prefer manual container control on an existing clone:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app npm run dev   # optional: Vite HMR inside container
```

Sail wrapper (uses `compose.yaml`):

```bash
composer sail -- up -d
composer sail -- artisan migrate
composer sail -- npm run dev
```

See `.env.example` for Docker-related variables (`DB_HOST`, `REDIS_HOST`, forwarded ports).

## Architecture

- Business logic lives under `app/Domain/{Context}/` (actions, DTOs, models, services).
- Team-owned models use `App\Domain\Shared\HasTeamScope`.
- Controllers stay thin; call actions/services from `app/Http/Controllers/Web/`.
- UI: Inertia + Vue 3 in `resources/js/Pages/`.
- Ledger amounts use `brick/money` and cents; bank import lines use decimal columns separately.

Internal AI/editor conventions may live in `.cursor/rules` — optional for human contributors.

## Frontend stack

- Inertia.js v2, Vue 3, Pinia, Ziggy
- Tailwind CSS v4 via `@tailwindcss/vite`
- shadcn-vue (`components.json`) — add components with `npx shadcn-vue@latest add <name>`
- Charts: vue-echarts; forms: vee-validate + zod; dates: dayjs

## Backend packages (high level)

`brick/money`, Spatie (permission, activity log, media library, backup), PhpSpreadsheet (VAT export), Laravel Pennant, Cashier, Horizon, Octane, Sanctum.

- **Horizon** requires Redis (`QUEUE_CONNECTION=redis`).
- **Cashier** needs Stripe keys in `.env` when billing is enabled.

## First-time app setup

On an empty database:

```bash
php artisan app:install
```

Interactive admin user and company team. Further setup (company profile, chart of accounts, VAT) happens in the in-app wizard after sign-in.

## Useful commands

| Command | Purpose |
|---------|---------|
| `php artisan app:install` | First install |
| `php artisan app:update` | Production upgrade (migrate, caches, workers) |
| `php artisan horizon` | Queue dashboard (local) |
| `composer test` | Run test suite |

## Contributing

See [CONTRIBUTING.md](../CONTRIBUTING.md).
