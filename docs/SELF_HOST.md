# Self-hosting nrth

This guide is for anyone who wants to run their own copy of nrth with **minimal setup**. You only need **Docker** and **Docker Compose** — PostgreSQL, Redis, MinIO (file storage), and the PHP app are all included.

For day-to-day development on your laptop, see [README.md](../README.md). For the maintainer’s auto-updating homelab server, see [PERSONAL_SERVER.md](PERSONAL_SERVER.md).

---

## What you get

The default `compose.yaml` stack runs:

| Service | Purpose |
|---------|---------|
| **app** | Laravel Octane (HTTP on port **8000**) |
| **worker** | Horizon (queues) |
| **scheduler** | `schedule:work` |
| **postgres** | Database |
| **redis** | Cache, sessions, queues |
| **minio** | S3-compatible storage (receipts, uploads) |
| **mailpit** | Catches outbound mail in dev (replace with real SMTP for production) |

---

## Quick start (about 10 minutes)

### 1. Install Docker

- [Docker Engine](https://docs.docker.com/engine/install/) + [Compose plugin](https://docs.docker.com/compose/install/) on Linux, macOS, or Windows.

### 2. Get the code

```bash
git clone https://github.com/mortolian/nrth.git
cd nrth
```

(Or download a release archive from the project’s releases page and extract it.)

### 3. Configure environment

```bash
cp .env.example .env
```

Edit `.env`. At minimum, change these before exposing the app to a network:

| Variable | Example | Notes |
|----------|---------|--------|
| `APP_NAME` | `My Company` | Shown in the UI |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Never `true` on the public internet |
| `APP_URL` | `https://books.example.com` | Full public URL |
| `APP_KEY` | *(generate)* | See step 4 |
| `DB_PASSWORD` | strong random | Used by Postgres container |
| `MINIO_ROOT_PASSWORD` | strong random | Object storage admin |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | match MinIO setup | See `.env.example` |

For Docker Compose, leave these as in `.env.example` unless you know you need to change them:

- `DB_CONNECTION=pgsql`
- `DB_HOST` / `REDIS_HOST` — overridden inside containers by `compose.yaml`
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`

Generate an application key:

```bash
docker compose run --rm app php artisan key:generate
```

### 4. Install and start

**Option A — helper script**

```bash
chmod +x scripts/self-host-install.sh
./scripts/self-host-install.sh
```

**Option B — manual**

```bash
docker compose up -d --build
docker compose exec app php artisan app:install
```

The installer asks for your name, email, company name, and password, then seeds the South African chart of accounts and default VAT rates.

### 5. Open the app

Default URL: **http://localhost:8000** (or the host port set in `APP_PORT`).

---

## Production checklist

Before putting the app on the internet:

1. **`APP_DEBUG=false`** and **`APP_ENV=production`**
2. **HTTPS** in front of port 8000 (Caddy or Nginx reverse proxy — example below)
3. **Firewall**: expose only 80/443; do not publish Postgres/Redis/MinIO ports publicly
4. **Real mail**: set `MAIL_*` to your SMTP provider (Mailpit is for testing only)
5. **Backups**: schedule `php artisan backup:run` (Spatie Backup is included) and back up Postgres + `storage` volumes
6. **Secrets**: never commit `.env`

### HTTPS with Caddy (simple)

On the same host as Docker, install [Caddy](https://caddyserver.com/) and use a site block:

```caddy
books.example.com {
    reverse_proxy localhost:8000
}
```

Caddy obtains and renews Let’s Encrypt certificates automatically.

---

## Updating to a new version

When a new release is available:

```bash
cd /path/to/nrth
git pull origin master   # or extract a new release archive
./scripts/deploy.sh production
```

`deploy.sh production` runs `php artisan app:update` (maintenance mode, migrations, cache rebuild, queue restart).

If you deployed from a **release tarball** instead of git:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
docker compose exec app php artisan app:update
```

---

## Useful commands

```bash
# View logs
docker compose logs -f app

# Run artisan
docker compose exec app php artisan migrate:status

# Stop everything
docker compose down

# Stop and remove data volumes (destructive)
docker compose down -v
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| “Vite manifest not found” | `docker compose exec app npm ci && npm run build` |
| Database connection errors | Wait for Postgres healthcheck; check `DB_*` in `.env` |
| Blank page / 500 | `docker compose logs app`; ensure `APP_KEY` is set |
| Queues not running | `docker compose ps worker`; `docker compose restart worker` |
| Emails not delivered | Configure real `MAIL_*`; Mailpit only captures mail locally |

More detail: [INSTALL.md](../INSTALL.md).
