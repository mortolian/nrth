# Self-hosting nrth

Run your own copy of nrth with one install command. For day-to-day development on your laptop, see [DEVELOPMENT.md](DEVELOPMENT.md). For the maintainer's personal dev server (manual deploy), see [PERSONAL_SERVER.md](PERSONAL_SERVER.md).

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

---

## Recovering a broken installation

If HTTPS errors, deploy failures, docker permission errors, Postgres password mismatches, Vite manifest errors, or wrong `APP_URL` have left the app unusable, use one of these paths.

### Choose a path

| Situation | Path | Data |
|-----------|------|------|
| No data to keep — start over | **A: Nuclear reset** | Wiped |
| Users/transactions exist — fix in place | **B: Surgical repair** | Preserved |

### Path A — Nuclear reset (no data to keep)

Destroys all Docker volumes (database, uploads, Redis, MinIO) and re-runs install.

```bash
cd /opt/nrth
git pull origin master   # get latest scripts (repair.sh, reset.sh)
./scripts/reset.sh --force --lan --install-dir /opt/nrth
```

`--lan` sets pragmatic defaults: plain HTTP on port 8000, `APP_URL=http://YOUR_LAN_IP:8000`.

After install completes, create your admin account if prompted:

```bash
./scripts/compose.sh exec -it app php artisan app:install
```

**Open:** `http://192.168.1.204:8000` (replace with your server IP).

### Path B — Surgical repair (keep database/users)

Fixes `.env`, syncs Postgres password to match `.env`, rebuilds Vite assets, and restarts the stack **without** deleting volumes.

```bash
cd /opt/nrth
git pull origin master
sudo chown -R "$USER:$USER" /opt/nrth    # if install was run with sudo
./scripts/repair.sh --install-dir /opt/nrth --ip 192.168.1.204
```

Default mode is **HTTP** (fastest way to get a working browser session on a LAN dev server).

For **HTTPS with Caddy** (self-signed cert on port 443):

```bash
./scripts/repair.sh --install-dir /opt/nrth --mode https --ip 192.168.1.204
```

**Open:** `https://192.168.1.204/` (accept the certificate warning). Do **not** use `:8000` with HTTPS.

### Recommended LAN dev defaults

For a private LAN server (e.g. `192.168.1.204`) where you need the app working today:

| Setting | HTTP (simplest) | HTTPS (Caddy) |
|---------|-----------------|---------------|
| `APP_URL` | `http://192.168.1.204:8000` | `https://192.168.1.204` |
| `APP_ALLOW_HTTP` | `true` | `false` |
| `COMPOSE_PROFILES` | *(unset)* | `proxy` |
| `CADDY_TLS` | — | `internal` |
| Browser URL | `http://192.168.1.204:8000` | `https://192.168.1.204/` |

`repair.sh --mode http` or `install.sh --lan` applies the HTTP column automatically.

### Manual fixes (if scripts are unavailable)

**Docker permission denied:**

```bash
./scripts/compose.sh ps          # auto-sudo wrapper
newgrp docker                    # or log out/in after install
sudo usermod -aG docker "$USER"
```

**Postgres password mismatch** (`.env` rotated but volume kept old password):

```bash
cd /opt/nrth
DB_PASS="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"
./scripts/compose.sh exec -T postgres psql -U dbuser -d postgres \
  -c "ALTER USER dbuser WITH PASSWORD '${DB_PASS}';"
./scripts/compose.sh restart app worker scheduler
```

**Vite manifest not found:**

```bash
./scripts/compose.sh exec app npm ci && ./scripts/compose.sh exec app npm run build
./scripts/compose.sh restart app
```

**Wrong APP_URL** (redirect loops, `2.wire.1`, hostname confusion):

```bash
# HTTP LAN access — edit /opt/nrth/.env:
#   APP_URL=http://192.168.1.204:8000
#   APP_ALLOW_HTTP=true
#   APP_FORCE_HTTPS=false
# Remove COMPOSE_PROFILES, CADDY_SITE, CADDY_TLS lines if present
./scripts/compose.sh restart app
```

### Verify after repair

```bash
cd /opt/nrth
./scripts/compose.sh ps
./scripts/compose.sh exec app curl -fsS http://127.0.0.1:8000/up
curl -v http://127.0.0.1:8000/up
```

If internal `/up` succeeds but the browser fails, the problem is URL/TLS — not the app container.

### Re-run install on a half-broken instance

`install.sh` detects an unhealthy stack and runs `repair.sh` automatically:

```bash
cd /opt/nrth
./scripts/install.sh --lan --install-dir /opt/nrth
# or explicitly:
./scripts/install.sh --repair --lan-ip 192.168.1.204
```

If users and data already exist and the stack is healthy, re-run install delegates to `deploy.sh` (data-safe upgrade).
