# Self-hosting nrth

Run your own copy of nrth with one install command. For day-to-day development on your laptop, see [DEVELOPMENT.md](DEVELOPMENT.md). For the maintainer's auto-updating homelab server, see [PERSONAL_SERVER.md](PERSONAL_SERVER.md).

---

## Install

On Ubuntu 22.04/24.04:

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --production --install-dir /opt/nrth
```

For a private LAN dev server, omit `--production` (defaults to dev settings).

All flags and options: **[INSTALL.md](INSTALL.md)**.

---

## What you get

| Service | Purpose |
|---------|---------|
| **app** | Laravel Octane (HTTP on port **8000**) |
| **worker** | Horizon (queues) |
| **scheduler** | `schedule:work` |
| **postgres** | Database |
| **redis** | Cache, sessions, queues |
| **minio** | S3-compatible storage (receipts, uploads) |
| **mailpit** | Catches outbound mail in dev (replace with real SMTP for production) |

Open **https://localhost:8000** (or the host port set in `APP_PORT`). Browsers may warn about the certificate until you terminate TLS in front of the app.

---

## Production checklist

Before putting the app on the internet:

1. **`APP_DEBUG=false`** and **`APP_ENV=production`** (set by `--production` install)
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

Caddy obtains and renews Let's Encrypt certificates automatically.

---

## Updating

```bash
cd /opt/nrth
./scripts/deploy.sh production
```

This is **data-safe**: it does not remove Docker volumes or run `migrate:fresh`. Only pending migrations are applied.

Or re-run install (detects existing install and delegates to `deploy.sh`):

```bash
./scripts/install.sh --production
```

---

## Data safety

Normal upgrades and re-runs of `install.sh` / `deploy.sh` preserve your database, uploaded files (MinIO/storage volumes), and Redis data.

**Do not run these unless you intend to wipe data:**

- `./scripts/compose.sh down -v` — blocked unless you add `--force` (deletes all Compose volumes)
- `php artisan migrate:fresh` or `db:wipe` — empties the database
- Manually changing `DB_PASSWORD` or `MINIO_ROOT_PASSWORD` in `.env` after first boot — breaks auth until you sync credentials (see [INSTALL.md](INSTALL.md#troubleshooting))

Safe shutdown: `./scripts/compose.sh down` (containers stop; volumes remain).

Full reference: [INSTALL.md — Data safety](INSTALL.md#data-safety).

---

## Useful commands

Use `./scripts/compose.sh` instead of `docker compose` — it auto-sudo's when your user cannot access the Docker socket yet (common right after install).

```bash
# View logs
./scripts/compose.sh logs -f app

# Run artisan
./scripts/compose.sh exec app php artisan migrate:status

# Stop everything (containers only — data preserved)
./scripts/compose.sh down

# Stop and remove data volumes (destructive — requires --force)
./scripts/compose.sh down -v --force
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| `permission denied` on docker.sock | `./scripts/compose.sh …`, or `newgrp docker`, or log out/in |
| "Vite manifest not found" | `./scripts/compose.sh exec app npm ci && npm run build` |
| Database connection errors | Wait for Postgres healthcheck; check `DB_*` in `.env` |
| Blank page / 500 | `./scripts/compose.sh logs app`; ensure `APP_KEY` is set |
| Queues not running | `./scripts/compose.sh ps worker`; `./scripts/compose.sh restart worker` |
| Emails not delivered | Configure real `MAIL_*`; Mailpit only captures mail locally |

More detail: [INSTALL.md](INSTALL.md).
