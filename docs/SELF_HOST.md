# Self-hosting nrth

Run your own copy of nrth with one install command. For day-to-day development on your laptop, see [DEVELOPMENT.md](DEVELOPMENT.md). For the maintainer's auto-updating homelab server, see [PERSONAL_SERVER.md](PERSONAL_SERVER.md).

---

## Install

On Ubuntu 22.04/24.04:

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --production --install-dir /opt/nrth
```

Production installs enable the optional **Caddy** reverse proxy (`--with-caddy`, default for `--production`). For a private LAN dev server, omit `--production` (defaults to dev settings).

All flags and options: **[INSTALL.md](INSTALL.md)**.

---

## What you get

| Service | Purpose |
|---------|---------|
| **app** | Laravel Octane (plain **HTTP** on port **8000** — not TLS) |
| **caddy** *(optional, profile `proxy`)* | Terminates HTTPS on **443**, forwards to `app:8000` |
| **worker** | Horizon (queues) |
| **scheduler** | `schedule:work` |
| **postgres** | Database |
| **redis** | Cache, sessions, queues |
| **minio** | S3-compatible storage (receipts, uploads) |
| **mailpit** | Catches outbound mail in dev (replace with real SMTP for production) |

**Users should open `https://your-host/` (port 443), not `https://your-host:8000`.** Port 8000 is Octane's internal HTTP port. Docker health checks use `http://127.0.0.1:8000/up` inside the container.

---

## HTTPS is required

nrth is a financial application. **Do not expose plain HTTP to users long-term.**

- Octane listens on **HTTP port 8000** inside Docker. That is normal — terminate TLS in **Caddy** (included in Compose) or **Nginx** on the host.
- Set `APP_URL` to your public **`https://`** URL (no `:8000` when using Caddy on 443).
- The installer sets `APP_FORCE_HTTPS=true`, `APP_ALLOW_HTTP=false`, and `TRUSTED_PROXIES=*` so the app reads `X-Forwarded-Proto` from the reverse proxy.
- Do **not** point browsers at `https://…:8000` — nothing speaks TLS on that port.

For local development on your laptop, set `APP_ALLOW_HTTP=true` in `.env` (see [DEVELOPMENT.md](DEVELOPMENT.md)). Never enable that on a server others can reach.

---

## Caddy in Docker Compose (recommended)

Production installs set `COMPOSE_PROFILES=proxy` and start the `caddy` service automatically.

### LAN / private IP (e.g. `192.168.1.204`)

In `/opt/nrth/.env`:

```env
COMPOSE_PROFILES=proxy
APP_URL=https://192.168.1.204
CADDY_SITE=192.168.1.204
CADDY_TLS=internal
APP_FORCE_HTTPS=true
APP_ALLOW_HTTP=false
```

Then:

```bash
cd /opt/nrth
./scripts/compose.sh up -d
```

Open **https://192.168.1.204/** and accept the self-signed certificate warning (browser-specific: "Advanced" → proceed).

### Public domain (Let's Encrypt)

```env
COMPOSE_PROFILES=proxy
APP_URL=https://books.example.com
CADDY_SITE=books.example.com
CADDY_TLS=off
```

Port **80** must be reachable from the internet for ACME HTTP validation. Caddy obtains and renews certificates automatically.

---

## Verify the app is running

On the server:

```bash
cd /opt/nrth

# Containers up?
./scripts/compose.sh ps

# Health check inside the app container (always HTTP)
./scripts/compose.sh exec app curl -fsS http://127.0.0.1:8000/up

# From the host (Octane HTTP on published APP_PORT, default 8000)
curl -v http://127.0.0.1:8000/up

# With Caddy enabled — HTTPS on 443
curl -vk https://127.0.0.1/up
```

If the internal `/up` check succeeds but the browser fails, the problem is TLS/URL configuration — not Octane.

---

## Temporary LAN access (HTTP only)

Use this only to confirm the app works; revert before others use the server.

```bash
cd /opt/nrth
# Edit .env:
#   APP_ALLOW_HTTP=true
#   APP_URL=http://192.168.1.204:8000
./scripts/compose.sh restart app
```

Open **http://192.168.1.204:8000** (note **`http://`**, not `https://`).

---

## Why common URLs fail

| URL | Why it fails |
|-----|----------------|
| `https://192.168.1.204:8000/` | Port 8000 is plain HTTP (Octane). The browser expects TLS → connection error or wrong protocol. |
| `https://192.168.1.204/` | Nothing listening on 443 until Caddy (or host Nginx) is running. |
| `http://192.168.1.204:8000/` with `APP_ALLOW_HTTP=false` | App redirects every request to HTTPS (which then fails as above). |

---

## HTTPS with Caddy on the host (alternative)

If you prefer not to use the Compose `caddy` service, install [Caddy](https://caddyserver.com/) on Ubuntu and proxy to the published app port:

```caddy
books.example.com {
    reverse_proxy localhost:8000
}
```

For a LAN IP with self-signed certs:

```caddy
192.168.1.204 {
    tls internal
    reverse_proxy localhost:8000
}
```

Set `APP_URL=https://192.168.1.204` (or your domain) and keep `TRUSTED_PROXIES=*`.

---

## Production checklist

Before putting the app on the internet:

1. **`APP_DEBUG=false`** and **`APP_ENV=production`** (set by `--production` install)
2. **HTTPS on 443** — Caddy in Compose or on the host; plain HTTP on 8000 is not for browsers
3. **`APP_URL=https://your-domain`** — must use `https://` without `:8000` when using standard 443
4. **Firewall**: expose only 80/443; do not publish Postgres/Redis/MinIO ports publicly
5. **Real mail**: set `MAIL_*` to your SMTP provider (Mailpit is for testing only)
6. **Backups**: schedule `php artisan backup:run` (Spatie Backup is included) and back up Postgres + `storage` volumes
7. **Secrets**: never commit `.env`

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
./scripts/compose.sh logs -f caddy

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
| `https://IP:8000` connection refused / SSL error | Use `https://IP/` with Caddy, or temporary `http://IP:8000` with `APP_ALLOW_HTTP=true` |
| `https://IP/` connection refused | Enable Caddy: `COMPOSE_PROFILES=proxy` in `.env`, then `./scripts/compose.sh up -d` |
| Redirect loop or mixed content | Set `APP_URL` to match the browser URL; ensure `TRUSTED_PROXIES=*` |
| Self-signed cert warning on LAN | Expected with `CADDY_TLS=internal`; accept in browser or install your CA |
| `permission denied` on docker.sock | `./scripts/compose.sh …`, or `newgrp docker`, or log out/in |
| "Vite manifest not found" | `./scripts/compose.sh exec app npm ci && npm run build` |
| Database connection errors | Wait for Postgres healthcheck; check `DB_*` in `.env` |
| Blank page / 500 | `./scripts/compose.sh logs app`; ensure `APP_KEY` is set |
| Queues not running | `./scripts/compose.sh ps worker`; `./scripts/compose.sh restart worker` |
| Emails not delivered | Configure real `MAIL_*`; Mailpit only captures mail locally |

More detail: [INSTALL.md](INSTALL.md).
