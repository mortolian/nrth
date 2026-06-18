# Personal server (auto-deploy on push)

This guide is for **you** (the project maintainer): run nrth on a **home or office Docker server** that tracks `master` with almost no manual upgrade work while the project is still changing quickly.

**Goals:**

- Push to `master` → server updates itself within a minute or two
- No Kubernetes, no complex GitOps
- Fast iteration: most pushes only need `git pull` + migrations, not full image rebuilds

---

## Architecture (keep it simple)

```
GitHub (master) ──push──► GitHub Actions (self-hosted runner on your server)
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

This is ideal for an active development server. When you later want a stricter production host, use `./scripts/deploy.sh production` and a non-bind-mounted production compose overlay.

---

## One-time server setup

### 1. Prerequisites

- Linux host (Ubuntu 22.04+ is fine) with Docker Engine + Compose plugin
- Git
- Clone location, e.g. `/opt/nrth`:

```bash
sudo mkdir -p /opt/nrth
sudo chown "$USER:$USER" /opt/nrth
git clone git@github.com:YOUR_ORG/nrth.git /opt/nrth
cd /opt/nrth
```

### 2. Environment

```bash
cp .env.example .env
```

Suggested values for a **private dev/staging** server:

```env
APP_ENV=local          # or staging
APP_DEBUG=true         # okay on a trusted LAN / VPN
APP_URL=http://192.168.1.50:8000   # or https://nrth.home.example

DB_PASSWORD=choose-a-password
MINIO_ROOT_PASSWORD=choose-a-password
```

Generate `APP_KEY`:

```bash
docker compose run --rm app php artisan key:generate
```

### 3. Start the stack

```bash
docker compose up -d --build
docker compose exec app php artisan app:install
```

Bookmark: `http://<server-ip>:8000`

### 4. Install a GitHub Actions self-hosted runner

This is the **recommended** auto-deploy method: no inbound webhook port, works on a private LAN as long as the runner can reach GitHub.

On the server ([official docs](https://docs.github.com/en/actions/hosting-your-own-runners/managing-self-hosted-runners/about-self-hosted-runners)):

1. GitHub repo → **Settings → Actions → Runners → New self-hosted runner**
2. Follow the Linux install commands (download, configure, install service)
3. Give the runner a label, e.g. `nrth-server`

The workflow file `.github/workflows/deploy-personal-server.yml` (in this repo) runs on that runner when you push to `master`.

**Important:** the workflow calls `/opt/nrth/scripts/deploy.sh` — adjust the path in the workflow if you cloned elsewhere.

### 5. Enable the workflow

The workflow is limited to `runs-on: self-hosted` with your label. After the runner is online, every push to `master` triggers:

1. `git pull` on the server (via `deploy.sh`)
2. Conditional `composer install` / `npm build`
3. `php artisan migrate`
4. Queue / Horizon restart

Typical deploy time: **15–60 seconds** when only PHP/Vue files changed.

---

## Manual deploy

Any time you want to sync without pushing:

```bash
cd /opt/nrth
./scripts/deploy.sh
```

Production-style update (maintenance mode + cache rebuild):

```bash
./scripts/deploy.sh production
```

---

## What `deploy.sh` does

| Step | When |
|------|------|
| `git fetch` + `git reset --hard origin/master` | Unless `SKIP_GIT=1` |
| `composer install` | Only if `composer.lock` hash changed |
| `npm ci` + `npm run build` | Only if `package-lock.json` hash changed |
| `php artisan migrate` | **dev** mode (default) |
| `php artisan app:update` | **production** mode |
| `queue:restart` + restart `worker` | **dev** mode |

Octane’s file watcher reloads PHP workers for code edits; you usually **do not** need `docker compose build` on every push.

---

## Alternative: GitHub webhook (if you prefer)

If the server is reachable from the internet and you do not want a self-hosted runner:

1. Run a tiny webhook listener (e.g. [webhook](https://github.com/adnanh/webhook)) on the server
2. Configure GitHub **Settings → Webhooks** on push to `master`
3. On valid payload, run `/opt/nrth/scripts/deploy.sh`

Use a shared secret and HTTPS. The self-hosted runner is simpler for home networks.

---

## Optional: run Vite dev on the server

For hot module reload while testing on the LAN:

```bash
docker compose exec app npm run dev
```

Ensure `VITE_*` / HMR settings in `.env.example` match how you reach the server from your browser.

---

## When things go wrong

```bash
# App logs
docker compose logs -f app

# Worker / Horizon
docker compose logs -f worker

# Force full dependency refresh
rm -f storage/framework/.deploy-composer-hash storage/framework/.deploy-npm-hash
./scripts/deploy.sh

# Nuclear reset (destroys DB volumes)
docker compose down -v
docker compose up -d --build
docker compose exec app php artisan app:install
```

---

## Later: stricter production

When the project stabilises:

- Use `APP_ENV=production`, `APP_DEBUG=false`
- Put Caddy/Nginx in front with HTTPS ([SELF_HOST.md](SELF_HOST.md))
- Switch deploys to `./scripts/deploy.sh production`
- Consider a production compose file without bind mounts and without Octane `--watch`

You do not need to change the auto-deploy mechanism — only the deploy mode and environment.
