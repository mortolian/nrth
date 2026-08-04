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
# or with Sail:
./vendor/bin/sail test
```

PHPUnit forces SQLite in-memory via `phpunit.xml` and `tests/bootstrap.php` (hard-overrides Docker `DB_*` in `$_SERVER`). `RefreshDatabase` is additionally blocked from running `migrate:fresh` / `db:wipe` against anything except sqlite `:memory:` unless `NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1`. Vite is stubbed in `tests/TestCase.php` — a production asset build is not required for PHPUnit.

Destructive reset commands are blocked outside `APP_ENV=testing`, including when invoked through `./scripts/compose.sh`. If you truly mean to wipe your current DB, opt in explicitly for that shell only:

```bash
NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1 php artisan migrate:fresh
NRTH_ALLOW_DESTRUCTIVE_DATABASE_RESET=1 ./scripts/compose.sh exec -T app php artisan migrate:fresh
```

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
```

Sail wrapper (uses `compose.yaml`):

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

The `vite` service is behind the optional Compose `dev` profile so self-hosted stacks do not
expose HMR by default. For contributor Docker workflows, enable it explicitly:

```bash
COMPOSE_PROFILES=dev ./vendor/bin/sail up -d
```

Or add `COMPOSE_PROFILES=dev` to `.env` before starting the stack. Then Vite HMR is available
at `http://localhost:5173`; use `./vendor/bin/sail logs -f vite` or
`./vendor/bin/sail logs -f horizon` to inspect either service. See `.env.example` for
Docker-related variables (`DB_HOST`, `REDIS_HOST`, forwarded ports).

### Email (invitations, invoices, password reset)

Team invitations use Laravel mail **synchronously**. If `MAIL_MAILER=log` (the `.env.example` default), messages are written to `storage/logs/laravel.log` and **never reach an inbox**.

With Docker Compose / Sail, containers send via **Mailpit** (`smtp` → `mailpit:1025`). Open the catcher UI:

[http://localhost:8025](http://localhost:8025)

On the host (Mailpit port forwarded), set:

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

Then recreate app containers so env is picked up (`sail up -d` or `./scripts/compose.sh up -d`). Production must use a real SMTP provider — see [SELF_HOST.md](SELF_HOST.md).

### Backups & exports (local)

- Team owners use **Backups & exports** for data takeouts (Tax → Documents redirects there).
- The first user created is an instance operator. Public self-registration is disabled; for local access, use the installer-created account or create users/admins explicitly. Manage operators under **Backups & exports → Instance backup**. Optional: `NRTH_OPERATOR_EMAILS` as break-glass. For existing DBs with no operators: `php artisan nrth:promote-first-operator`.
- Takeout and instance backup jobs run on Horizon’s `long` queue (multi-minute). Restart Horizon after pulling changes: `./vendor/bin/sail restart horizon` (or `php artisan horizon:terminate`).
- Instance backups need `pg_dump` matching Compose Postgres (**16**). After Dockerfile client changes: `./vendor/bin/sail build` then recreate containers.
## Architecture

- Business logic lives under `app/Domain/{Context}/` (actions, DTOs, models, services).
- Team-owned models use `App\Domain\Shared\HasTeamScope`.
- Controllers stay thin; call actions/services from `app/Http/Controllers/Web/`.
- UI: Inertia + Vue 3 in `resources/js/Pages/`.
- Ledger amounts use `brick/money` and cents; bank import lines use decimal columns separately.
- Recurring invoices: schedule `php artisan schedule:work` (or cron `schedule:run`) so `invoices:generate-recurring` runs daily at 01:30.
- Sending or marking an invoice as sent posts an accrual journal (Dr AR, Cr income accounts + VAT). Subsequent payments clear AR only when that accrual exists.
- **Note templates** (Settings → Note templates, or command palette “Note Templates”): create named markdown snippets such as “International Banking Details”. Attach them on a client to prefill new invoices/estimates, or insert them while editing a document. Footers/terms stay freeform per document (markdown editor, no shared templates). Example body:

```markdown
**Bank:** First National Bank  
Account name: Acme (Pty) Ltd  
Account: `62012345678`  
Reference: invoice number
```

Copied text is stored on each document; editing a template later does not rewrite existing invoices.

Internal AI/editor conventions may live in `.cursor/rules` — optional for human contributors.

## Frontend stack

- Inertia.js v2, Vue 3, Pinia, Ziggy
- Tailwind CSS v4 via `@tailwindcss/vite`
- shadcn-vue (`components.json`) — add components with `npx shadcn-vue@latest add <name>`
- Charts: vue-echarts; forms: vee-validate + zod; dates: dayjs; markdown fields: [Tiptap](https://tiptap.dev/) (`@tiptap/vue-3` + `@tiptap/markdown`)

## Backend packages (high level)

`brick/money`, Spatie (permission, activity log, media library, backup), PhpSpreadsheet (VAT export), Laravel Pennant, Cashier, Horizon, Octane, Sanctum.

- **Horizon** requires Redis (`QUEUE_CONNECTION=redis`).
- **Cashier** needs Stripe keys in `.env` when billing is enabled.

## First-time app setup

On an empty database:

```bash
php artisan app:install
```

Interactive admin user and business team. Further setup (business profile, chart of accounts, VAT) happens in the in-app wizard after sign-in.

## Useful commands

| Command | Purpose |
|---------|---------|
| `php artisan app:install` | First install |
| `php artisan app:update` | Production upgrade (migrate, caches, workers) |
| `php artisan horizon` | Queue dashboard (local) |
| `composer test` | Run test suite |

## Contributing

See [CONTRIBUTING.md](../CONTRIBUTING.md).
