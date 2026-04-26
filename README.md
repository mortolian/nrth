# Contractor accounting & finance

South African contractor–focused accounting and finance app (set `APP_NAME` in `.env` for the UI and document title). Built with Laravel 13, Jetstream (Inertia + Teams), Vue 3, Tailwind CSS v4, and domain logic under `app/Domain`.

## Requirements

- PHP 8.3+ (project rules target 8.4+ when your runtime allows)
- [Composer](https://getcomposer.org/)
- **Node.js 20.19+ or 22.12+** (required by Vite 8 and `@tailwindcss/vite` 4)
- [Docker](https://docs.docker.com/get-docker/) and Docker Compose v2 (optional, for Sail or the self-hosted stack)

## Local setup

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

Run tests (Vite is stubbed in `tests/TestCase.php` so a production build is not required for PHPUnit):

```bash
php artisan test
```

## Frontend stack

- Inertia.js v2 (`@inertiajs/vue3`), Pinia, Ziggy
- Tailwind CSS v4 via `@tailwindcss/vite` (see `resources/css/app.css`)
- **shadcn-vue**: `components.json` and `resources/js/lib/utils.ts` are in place. Add primitives with the upstream CLI, for example:

```bash
npx shadcn-vue@latest add button
```

- Charts: `vue-echarts` and `echarts`
- Forms: `vee-validate` and `zod`; dates: `dayjs`

## Backend packages (high level)

`brick/money`, Spatie (permission, activity log, media library, backup, PDF), `maatwebsite/excel`, Laravel Pennant, Cashier, Horizon, Sanctum, and `barryvdh/laravel-ide-helper` (dev). Publish/migrate steps for these are already applied where the installers require it.

**Horizon** expects Redis. Set `QUEUE_CONNECTION=redis` and run Redis locally (or use `QUEUE_CONNECTION=database` without Horizon for simple development).

**Cashier** needs Stripe keys in `.env` (`STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) when you enable billing.

## Docker Compose (self-hosted)

One image (`docker/Dockerfile`, PHP **8.4** + **Swoole**, **Redis**, **pgsql**, **GD**, **Horizon**) powers **app** (Laravel **Octane** on port **8000**), **worker** (**Supervisor** + **Horizon**), and **scheduler** (`php artisan schedule:work`). Data services: **PostgreSQL 16**, **Redis 7**, **MinIO** (S3 API **9000**, console **9001**). The `createbuckets` one-shot service creates the `pennies` bucket.

```bash
docker compose build
docker compose up -d
```

Replace the example `APP_KEY` and MinIO credentials in `compose.yaml` (or inject via `--env-file`) before any internet-facing deployment. The volume name `mysql_data` is used for PostgreSQL data to match the phase-1.2 spec wording.

## Laravel Sail (local)

[Sail](https://laravel.com/docs/sail) uses **`compose.sail.yaml`** so it does not replace the production **`compose.yaml`**. Services mirror that stack: **PostgreSQL 16**, **Redis 7**, **MinIO** (API **9000**, console **9001**), **Mailpit** (SMTP **1025**, UI **8025**), plus **`createbuckets`** for the `pennies` bucket. The app container is **`laravel.test`** (PHP **8.4** + Swoole from Sail’s runtime).

```bash
composer sail -- up -d
composer sail -- artisan migrate
# App: http://localhost (default APP_PORT=80) — Vite: composer sail -- npm run dev
```

Stop containers: `composer sail -- down`.

Use a `.env` tuned for Sail (see `.env.example`): `DB_HOST=pgsql`, `REDIS_HOST=redis`, `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` set to **redis**, MinIO **`AWS_*`** values matching the production compose defaults, and **`MAIL_HOST=mailpit`**. The Composer script sets `SAIL_FILES=compose.sail.yaml` automatically.

## Multi-tenancy

Use `App\Domain\Shared\HasTeamScope` on team-owned Eloquent models so queries are limited to `auth()->user()->current_team_id`. For admin or Filament contexts that must see all teams, use `Model::queryWithoutTeamScope()` (see the trait).

## Project rules

See `.cursor/rules` for architecture (actions, DTOs, `brick/money`, double-entry, Filament boundaries). Prompt sequences live in `CURSOR_PROMPTS.md`.

## License

MIT (same as Laravel skeleton). Adjust if you distribute differently.
