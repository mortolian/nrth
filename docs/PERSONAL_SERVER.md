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

On Ubuntu 22.04/24.04, one command installs Docker, clones to `/opt/nrth`, configures `.env`, starts the stack, runs `app:install`, and sets up auto-deploy:

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --dev --auto-deploy --install-dir /opt/nrth
```

Or from a clone:

```bash
./scripts/install.sh --dev --auto-deploy --install-dir /opt/nrth
```

When prompted for auto-deploy, paste a runner registration token from GitHub (**Settings → Actions → Runners → New self-hosted runner**), or provide it up front:

```bash
GITHUB_RUNNER_TOKEN=<token> ./scripts/install.sh --auto-deploy --install-dir /opt/nrth
```

The installer configures a runner with label **`nrth-server`**.

Bookmark: `http://<server-ip>:8000`

### Enable the workflow

The workflow file `.github/workflows/deploy-personal-server.yml` runs on that runner when you push to `master`. It calls `/opt/nrth/scripts/deploy.sh` — adjust the path in the workflow if you used a different `--install-dir`.

After the runner is online, every push to `master` triggers:

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

Octane's file watcher reloads PHP workers for code edits; you usually **do not** need `docker compose build` on every push.

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
./scripts/install.sh --dev
```

---

## Later: stricter production

When the project stabilises:

- Use `APP_ENV=production`, `APP_DEBUG=false`
- Put Caddy/Nginx in front with HTTPS ([SELF_HOST.md](SELF_HOST.md))
- Switch deploys to `./scripts/deploy.sh production`
- Consider a production compose file without bind mounts and without Octane `--watch`

You do not need to change the auto-deploy mechanism — only the deploy mode and environment.
