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

Open **http://localhost:8000** (or the host port set in `APP_PORT`).

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

Or re-run install (detects existing install and delegates to `deploy.sh`):

```bash
./scripts/install.sh --production
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
| "Vite manifest not found" | `docker compose exec app npm ci && npm run build` |
| Database connection errors | Wait for Postgres healthcheck; check `DB_*` in `.env` |
| Blank page / 500 | `docker compose logs app`; ensure `APP_KEY` is set |
| Queues not running | `docker compose ps worker`; `docker compose restart worker` |
| Emails not delivered | Configure real `MAIL_*`; Mailpit only captures mail locally |

More detail: [INSTALL.md](INSTALL.md).
