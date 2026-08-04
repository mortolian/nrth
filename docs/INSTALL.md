# Getting started

Install once. Update with one command. That’s the whole happy path.

**Before any install:** make sure this machine is backed up. Piped/non-interactive installs require `--accept-data-risk`.

---

## 1. Install (Ubuntu 22.04/24.04)

**Production (HTTPS via Caddy):**

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh \
  | sudo bash -s -- --accept-data-risk --production --install-dir /opt/nrth
```

**Private LAN (HTTP on :8000):**

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh \
  | sudo bash -s -- --accept-data-risk --lan --install-dir /opt/nrth
```

`--lan` is **trusted private network only** — plain HTTP, no Caddy. Do not port-forward `:8000` (or Postgres/Redis) to the internet. For anything broader, use `--production` or turn on HTTPS later ([SELF_HOST.md](SELF_HOST.md)).

From a git clone: `./scripts/install.sh --production` (or `--lan`).

The installer installs Docker if needed, writes `.env`, starts Compose, and runs `app:install` (admin + business). Open the URL it prints and sign in.

Public self-registration is disabled. The installer-created admin account (and any later accounts created by the instance administrator) is the supported way into an instance.

For safety, self-hosted installs do **not** start the optional Vite HMR service by default.
Deployed instances should serve built assets from `public/build`, not `http://...:5173`.

If the admin wizard did not run (non-interactive install):

```bash
cd /opt/nrth
./scripts/compose.sh exec -it app php artisan app:install
```

Always use `./scripts/compose.sh` for Docker Compose (it handles sudo/docker group). Do not use bare `docker compose` in docs or habits.

---

## 2. Update (every time)

```bash
cd /opt/nrth
./scripts/update
```

Mode defaults to a **full** update (migrate, caches, asset build, verify). With `APP_ENV=production`, it also runs `app:update` (maintenance mode). Optional: `./scripts/update production` or `./scripts/update dev`.

`./scripts/deploy.sh` still works as an alias.

Re-running `install.sh` on an existing install also runs `./scripts/update` (data preserved).

---

## 3. Optional: update on every git push

Install a self-hosted runner once (`install.sh --auto-deploy`, label `nrth-server`). The workflow [deploy-server.yml](../.github/workflows/deploy-server.yml) runs `./scripts/update` on push to `master`. Details: [PERSONAL_SERVER.md](PERSONAL_SERVER.md).

---

## Flags (install only)

| Flag | Purpose |
|------|---------|
| `--production` | Production env + Caddy TLS (use this if the host is reachable beyond a trusted LAN) |
| `--lan` | Trusted LAN only: HTTP on :8000, no Caddy — not internet-safe |
| `--install-dir PATH` | Default `/opt/nrth` when piping |
| `--accept-data-risk` | Required for piped / non-interactive |
| `--auto-deploy` | Register GitHub Actions runner (`nrth-server`) |
| `--non-interactive` | Skip prompts; generated secrets |
| `--repair` | Non-destructive fix via `repair.sh` |

Full list: run `./scripts/install.sh --help`.

---

## Something broken?

| Problem | Fix |
|---------|-----|
| App won’t start / wrong URL / HTTPS mess | `./scripts/repair.sh --ip YOUR_IP` |
| Want a clean wipe | `./scripts/reset.sh --force` (destroys volumes) |
| Docker permission denied | Use `./scripts/compose.sh …`, or `newgrp docker` |
| DB password mismatch after `.env` edit | `./scripts/repair.sh` |
| Queues stuck | `./scripts/compose.sh restart horizon` |

More detail: [SELF_HOST.md](SELF_HOST.md) (HTTPS, backups, recovery).

---

## Guides

| Doc | For |
|-----|-----|
| [SELF_HOST.md](SELF_HOST.md) | HTTPS, backups, troubleshooting |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Local contributor setup |
| [PERSONAL_SERVER.md](PERSONAL_SERVER.md) | Maintainer push-to-deploy |
| [RELEASE.md](RELEASE.md) | Versioning / Release Please (maintainers) |
