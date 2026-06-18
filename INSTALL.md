# Installation & hosting

nrth can run on a laptop for development or on a server for daily use. **Docker Compose is the recommended path** for self-hosters and for a personal staging server.

| Guide | Audience | Summary |
|-------|----------|---------|
| **[docs/SELF_HOST.md](docs/SELF_HOST.md)** | Anyone self-hosting | Clone → `.env` → `docker compose up` → `app:install` |
| **[docs/PERSONAL_SERVER.md](docs/PERSONAL_SERVER.md)** | Project maintainer | Homelab Docker server that auto-deploys on push to `master` |
| **[README.md](README.md)** | Local developers | PHP + Node on the host, or Laravel Sail |

---

## Self-host quick start

```bash
git clone <repository-url> nrth && cd nrth
cp .env.example .env
# Edit .env — set APP_URL, passwords, APP_ENV=production, APP_DEBUG=false
chmod +x scripts/self-host-install.sh
./scripts/self-host-install.sh
```

Open `http://localhost:8000` (or your `APP_PORT`).

Full production checklist, HTTPS, and updates: **[docs/SELF_HOST.md](docs/SELF_HOST.md)**.

---

## Personal server quick start

```bash
git clone <repository-url> /opt/nrth && cd /opt/nrth
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan app:install
```

Install a **GitHub Actions self-hosted runner** on the same machine (label: `nrth-server`). Pushes to `master` run `/opt/nrth/scripts/deploy.sh` automatically.

Details: **[docs/PERSONAL_SERVER.md](docs/PERSONAL_SERVER.md)**.

---

## Artisan commands

| Command | When |
|---------|------|
| `php artisan app:install` | First install on an empty database (interactive admin setup) |
| `php artisan app:update` | Production upgrades (migrate, caches, restart workers) |

Inside Docker, prefix with `docker compose exec app`, e.g.:

```bash
docker compose exec app php artisan app:update
```

---

## Stack (Docker Compose)

- **PostgreSQL 16** — database (`pgsql`)
- **Redis 7** — cache, sessions, queues
- **MinIO** — S3-compatible file storage
- **Octane (Swoole)** — HTTP
- **Horizon** — queue worker
- **Mailpit** — local mail capture (replace with SMTP in production)

---

## Release archives (optional)

To build a tarball for air-gapped or non-git installs:

```bash
./scripts/build-release.sh
```

Extract on the target host and follow [docs/SELF_HOST.md](docs/SELF_HOST.md).

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Vite manifest missing | `docker compose exec app npm ci && npm run build` |
| Missing tables | `docker compose exec app php artisan migrate` |
| `storage/` permissions | Ensure the container can write to `storage` and `bootstrap/cache` |
| Queues stuck | `docker compose restart worker` |
| Mail not sent | Configure `MAIL_*` (Mailpit is dev-only) |

---

## Manual install (without Docker)

Only if you cannot use Docker:

- PHP 8.3+ with extensions listed in `app:install`
- PostgreSQL 16 (or MySQL 8 with `DB_CONNECTION=mysql`)
- Redis
- Node 20.19+ for `npm run build`
- Nginx/Apache → `public/`
- Supervisor for `php artisan horizon`
- Cron: `* * * * * php artisan schedule:run`

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan app:install
```

Docker remains simpler because dependencies and services are bundled.
