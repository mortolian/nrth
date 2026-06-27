# Installation

nrth installs with **one command** on Ubuntu 22.04/24.04 (recommended). The script installs Docker if needed, clones to `/opt/nrth`, configures `.env`, starts the Compose stack, and runs the first-time `app:install` wizard.

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash
```

### Flags

Pass flags after `bash -s --`:

```bash
# Production server
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --production --install-dir /opt/nrth

# Personal dev server with GitHub Actions auto-deploy
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --dev --auto-deploy --install-dir /opt/nrth

# Non-interactive (generated secrets, localhost URL)
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --production --non-interactive
```

| Flag | Purpose |
|------|---------|
| `--production` | `APP_ENV=production`, `APP_DEBUG=false`, Caddy TLS proxy enabled |
| `--dev` | Dev/staging defaults (default) |
| `--with-caddy` | Enable Compose Caddy reverse proxy on ports 80/443 |
| `--no-caddy` | Skip Caddy; you must terminate TLS elsewhere |
| `--auto-deploy` | Install GitHub Actions self-hosted runner (label `nrth-server`) |
| `--install-dir PATH` | Clone/install location (default `/opt/nrth` when piping) |
| `--repo-url URL` | Git remote (default `https://github.com/mortolian/nrth.git`) |
| `--branch NAME` | Git branch (default `master`) |
| `--non-interactive` | Skip env prompts; use generated secrets |
| `--allow-http` | Permit plain HTTP (`APP_ALLOW_HTTP=true`; LAN dev only) |
| `--lan` | Shorthand: `--dev --allow-http --no-caddy` with auto-detected LAN IP |
| `--lan-ip ADDR` | Set LAN IP for `APP_URL` (with `--lan` or `--allow-http`) |
| `--repair` | Non-destructive fix via `scripts/repair.sh` |

From an existing clone:

```bash
./scripts/install.sh --production --install-dir /opt/nrth
```

Re-running `install.sh` on an already-installed instance runs `scripts/deploy.sh` instead of a full bootstrap. Existing Docker volumes and database data are preserved. If the stack is unhealthy, install automatically runs `scripts/repair.sh`.

### Recovering a broken install

| Goal | Command |
|------|---------|
| Fix in place (keep data) | `./scripts/repair.sh --ip 192.168.1.204` |
| HTTPS via Caddy on LAN | `./scripts/repair.sh --mode https --ip 192.168.1.204` |
| Wipe everything and reinstall | `./scripts/reset.sh --force --lan` |
| Re-run install + auto-repair | `./scripts/install.sh --lan --install-dir /opt/nrth` |

Full walkthrough: **[SELF_HOST.md — Recovering a broken installation](SELF_HOST.md#recovering-a-broken-installation)**.

### Auto-deploy token

With `--auto-deploy`, paste a runner registration token when prompted, or set:

```bash
GITHUB_RUNNER_TOKEN=<token> ./scripts/install.sh --auto-deploy
```

See [PERSONAL_SERVER.md](PERSONAL_SERVER.md) for maintainer workflow details.

---

## After install

Open the URL shown at the end of install (your `APP_URL`). **HTTPS is required for browsers** — Octane serves plain HTTP on port 8000 inside Docker; TLS terminates in Caddy (included with `--production`) or an external reverse proxy. Production hardening: **[SELF_HOST.md](SELF_HOST.md)**.

If the installer did not create your admin account (non-interactive install, or `permission denied` on docker.sock), run:

```bash
cd /opt/nrth
./scripts/compose.sh exec -it app php artisan app:install
```

(`./scripts/compose.sh` auto-sudo's until you log out/in or run `newgrp docker` after being added to the docker group.)

Updates:

```bash
/opt/nrth/scripts/deploy.sh          # dev/staging
/opt/nrth/scripts/deploy.sh production
```

---

## Stack (Docker Compose)

- **PostgreSQL 16** — database
- **Redis 7** — cache, sessions, queues
- **MinIO** — S3-compatible file storage
- **Octane (Swoole)** — HTTP
- **Horizon** — queue worker
- **Mailpit** — local mail capture (replace with SMTP in production)

---

## Guides

| Guide | Audience |
|-------|----------|
| [SELF_HOST.md](SELF_HOST.md) | Production checklist, HTTPS, backups |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Contributors (local dev after cloning from git) |
| [PERSONAL_SERVER.md](PERSONAL_SERVER.md) | Maintainer auto-deploy server |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | How to report issues and open PRs |
| [SECURITY.md](../SECURITY.md) | Vulnerability reporting |

---

## Artisan commands

| Command | When |
|---------|------|
| `./scripts/compose.sh exec -it app php artisan app:install` | First install on an empty database |
| `./scripts/deploy.sh production` | Production upgrades (recommended) |
| `./scripts/compose.sh exec app php artisan app:update` | Manual production upgrade |

Use `./scripts/compose.sh` instead of `docker compose` — it auto-sudo's when docker.sock is not accessible yet.

---

## Data safety

**Safe by default (data preserved):**

| Action | What happens |
|--------|----------------|
| `./scripts/install.sh` (re-run on existing install) | Detects volumes and admin user; runs `deploy.sh` only |
| `./scripts/deploy.sh` / `deploy.sh production` | Pulls code, runs **incremental** `migrate`, rebuilds caches; **no** volume removal |
| `./scripts/compose.sh down` | Stops containers; **keeps** Postgres, Redis, MinIO, and storage volumes |
| `php artisan app:update` / `migrate` | Applies new migrations only; does not drop tables |

**Destructive (will delete data — use only when you mean to reset):**

| Action | What is lost |
|--------|----------------|
| `./scripts/compose.sh down -v` | All Docker volumes: database, uploads, Redis, MinIO objects |
| `./scripts/compose.sh down -v --force` | Same as above; `--force` is required to bypass the safety guard |
| `./scripts/reset.sh --force` | Stops stack, removes all volumes, re-runs `install.sh` (backs up old `.env`) |
| `php artisan migrate:fresh` | All database tables and rows |
| `php artisan db:wipe` | Database contents |
| Re-generating `DB_PASSWORD` / `MINIO_ROOT_PASSWORD` in `.env` **after** volumes exist | App cannot connect until passwords are synced (data still on disk) |

`./scripts/compose.sh` blocks `down -v` unless you pass `--force` or set `NRTH_FORCE=1`.

On re-run, `install.sh` preserves `DB_PASSWORD` and MinIO credentials when data volumes already exist (Postgres/MinIO only read those env vars on first volume init).

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Broken install (multiple issues) | `./scripts/repair.sh --ip YOUR_LAN_IP` — see [SELF_HOST.md](SELF_HOST.md#recovering-a-broken-installation) |
| Start over (no data to keep) | `./scripts/reset.sh --force --lan` |
| `password authentication failed for user "dbuser"` | Re-running install rotated `DB_PASSWORD` in `.env` while Postgres kept the old password in its volume. **Keep data:** `./scripts/repair.sh` (syncs password) or manual `ALTER USER` below. **Fresh start (destructive):** `./scripts/reset.sh --force` |
| `permission denied` on `/var/run/docker.sock` | `./scripts/compose.sh …` (auto-sudo), or `newgrp docker`, or log out/in after install |
| Vite manifest missing | `./scripts/compose.sh exec app npm ci && npm run build` |
| Missing tables | `./scripts/compose.sh exec app php artisan migrate` |
| `storage/` permissions | Ensure the container can write to `storage` and `bootstrap/cache` |
| Queues stuck | `./scripts/compose.sh restart worker` |
| Mail not sent | Configure `MAIL_*` (Mailpit is dev-only) |

**DB password mismatch (keep existing data):**

```bash
cd /opt/nrth   # or your install dir
DB_PASS="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"
./scripts/compose.sh exec -T postgres psql -U dbuser -d postgres \
  -c "ALTER USER dbuser WITH PASSWORD '${DB_PASS}';"
./scripts/compose.sh restart app worker scheduler
./scripts/compose.sh exec -it app php artisan app:install
```

If you still have the original `.env` from the first install, you can instead restore that `DB_PASSWORD` value and restart the app containers.
