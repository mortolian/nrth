# Personal server (maintainer dev host)

**Maintainer-only.** This guide is for **you** (the project maintainer): run nrth on a **home or office Docker server** for fast iteration while the project is still changing quickly.

**Self-hosters and production deployments:** follow [SELF_HOST.md](SELF_HOST.md) and [INSTALL.md](INSTALL.md) — not this guide.

**Goals:**

- Pull latest `master` and deploy with one command (`./scripts/deploy.sh`)
- No Kubernetes, no complex GitOps
- Fast iteration: most updates only need `git pull` + migrations, not full image rebuilds

---

## Architecture (keep it simple)

```
Your workstation ──git push──► GitHub (master)
                                    │
Server (manual or custom workflow) ─┘
        │
        ▼
/opt/nrth/scripts/deploy.sh
        │
git pull + migrate + restart queues
        │
Docker Compose (bind-mounted code)
```

The included `compose.yaml` **bind-mounts your source tree** into the app container and runs Octane with `--watch`. That means:

- **PHP / Vue / config changes** from `git pull` are picked up without rebuilding the Docker image
- **composer.lock** or **package-lock.json** changes trigger install/build automatically via `deploy.sh`
- **Database changes** run via `migrate` on each deploy

This is ideal for an active development server. When you later want a stricter production host, use `./scripts/deploy.sh production` and a non-bind-mounted production compose overlay — see [SELF_HOST.md](SELF_HOST.md).

Use `./scripts/compose.sh` for all Docker Compose commands (auto-sudo when needed). Do not use bare `docker compose` on the server.

---

## One-time server setup

On Ubuntu 22.04/24.04, one command installs Docker, clones to `/opt/nrth`, configures `.env`, starts the stack, and runs `app:install`.

Piped installs are non-interactive and require `--accept-data-risk` (you will be prompted interactively when running from a clone):

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --accept-data-risk --lan --install-dir /opt/nrth
```

Or from a clone:

```bash
./scripts/install.sh --lan --install-dir /opt/nrth
```

`--lan` sets pragmatic LAN dev defaults: plain HTTP on port 8000, `APP_URL=http://YOUR_LAN_IP:8000`, no Caddy.

When prompted (or before install), confirm you have verified backups — the installer asks you to acknowledge backup responsibility before it changes anything.

**Bookmark:**

| Mode | URL |
|------|-----|
| LAN dev (`--lan`, default here) | `http://<server-ip>:8000` |
| Production with Caddy | `https://<server-ip>/` or `https://your-domain/` (port 443, not `:8000`) |

See [SELF_HOST.md](SELF_HOST.md) for HTTPS/Caddy setup when you move off plain HTTP.

---

## Deploy updates

After you push to `master`, sync the server manually:

```bash
cd /opt/nrth
./scripts/deploy.sh
```

This runs:

1. `git pull` on the server
2. Conditional `composer install` / `npm build`
3. `php artisan migrate`
4. Queue / Horizon restart

Typical deploy time: **15–60 seconds** when only PHP/Vue files changed.

Production-style update (maintenance mode + cache rebuild):

```bash
./scripts/deploy.sh production
```

---

## Optional: GitHub Actions self-hosted runner

The repo does **not** ship a deploy workflow. If you want push-to-deploy, add your own workflow under `.github/workflows/` on your fork or in a private overlay, and point it at `${INSTALL_DIR}/scripts/deploy.sh`.

`install.sh --auto-deploy` only registers a self-hosted runner (label **`nrth-server`**) on this machine — useful once you have a workflow that targets that label.

When prompted, paste a runner registration token from GitHub (**Settings → Actions → Runners → New self-hosted runner**), or provide it up front:

```bash
GITHUB_RUNNER_TOKEN=<token> ./scripts/install.sh --accept-data-risk --lan --auto-deploy --install-dir /opt/nrth
```

Example workflow step (adjust the install path if you used a different `--install-dir`):

```yaml
jobs:
  deploy:
    runs-on: [self-hosted, nrth-server]
    steps:
      - name: Deploy latest master
        run: /opt/nrth/scripts/deploy.sh
```

---

## What `deploy.sh` does

Data-safe: no volume removal, no `migrate:fresh`. `git reset --hard` only affects tracked files in the git clone (not Docker volumes).

| Step | When |
|------|------|
| `git fetch` + `git reset --hard origin/master` | Unless `SKIP_GIT=1` (discards uncommitted changes in the clone) |
| `composer install` | Only if `composer.lock` hash changed |
| `npm ci` + `npm run build` | Only if `package-lock.json` hash changed |
| `php artisan migrate` | **dev** mode (default) |
| `php artisan app:update` | **production** mode |
| `queue:restart` + restart `worker` | **dev** mode |

Octane's file watcher reloads PHP workers for code edits; you usually **do not** need `./scripts/compose.sh build` on every push.

---

## Data safety

Normal upgrades via `deploy.sh` and re-runs of `install.sh` preserve your database, uploaded files (MinIO/storage volumes), and Redis data.

**Do not run these unless you intend to wipe data:**

- `./scripts/compose.sh down -v` — blocked unless you add `--force` (deletes all Compose volumes)
- `php artisan migrate:fresh` or `db:wipe` — empties the database
- Manually changing `DB_PASSWORD` or `MINIO_ROOT_PASSWORD` in `.env` after first boot — breaks auth until you sync credentials

Safe shutdown: `./scripts/compose.sh down` (containers stop; volumes remain).

Full reference: [SELF_HOST.md — Data safety](SELF_HOST.md#data-safety) and [INSTALL.md](INSTALL.md).

---

## Optional: run Vite dev on the server

For hot module reload while testing on the LAN:

```bash
./scripts/compose.sh exec app npm run dev
```

Ensure `VITE_*` / HMR settings in `.env.example` match how you reach the server from your browser.

---

## When things go wrong

### Logs and deploy refresh

```bash
# App logs
./scripts/compose.sh logs -f app

# Worker / Horizon
./scripts/compose.sh logs -f worker

# Force full dependency refresh
rm -f storage/framework/.deploy-composer-hash storage/framework/.deploy-npm-hash
./scripts/deploy.sh
```

### Surgical repair (keep database and users)

Fixes `.env`, syncs Postgres password, rebuilds Vite assets, and restarts the stack **without** deleting volumes:

```bash
cd /opt/nrth
git pull origin master
./scripts/repair.sh --install-dir /opt/nrth --ip <server-ip>
```

Default mode is HTTP (`http://<server-ip>:8000`). For HTTPS with Caddy:

```bash
./scripts/repair.sh --install-dir /opt/nrth --mode https --ip <server-ip>
```

More detail: [SELF_HOST.md — Recovering a broken installation](SELF_HOST.md#recovering-a-broken-installation).

### Nuclear reset (destroys all data)

Destroys all Docker volumes — database, uploads, Redis, MinIO:

```bash
./scripts/reset.sh --force --accept-data-risk --lan --install-dir /opt/nrth
```

After reset, create your admin account if needed:

```bash
./scripts/compose.sh exec -it app php artisan app:install
```

---

## Later: stricter production

When the project stabilises:

- Use `APP_ENV=production`, `APP_DEBUG=false`
- Put Caddy in front with HTTPS ([SELF_HOST.md](SELF_HOST.md))
- Switch deploys to `./scripts/deploy.sh production`
- Consider a production compose file without bind mounts and without Octane `--watch`
