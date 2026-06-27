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
| `--production` | `APP_ENV=production`, `APP_DEBUG=false` |
| `--dev` | Dev/staging defaults (default) |
| `--auto-deploy` | Install GitHub Actions self-hosted runner (label `nrth-server`) |
| `--install-dir PATH` | Clone/install location (default `/opt/nrth` when piping) |
| `--repo-url URL` | Git remote (default `https://github.com/mortolian/nrth.git`) |
| `--branch NAME` | Git branch (default `master`) |
| `--non-interactive` | Skip env prompts; use generated secrets |

From an existing clone:

```bash
./scripts/install.sh --production --install-dir /opt/nrth
```

Re-running `install.sh` on an already-installed instance runs `scripts/deploy.sh` instead of a full bootstrap.

### Auto-deploy token

With `--auto-deploy`, paste a runner registration token when prompted, or set:

```bash
GITHUB_RUNNER_TOKEN=<token> ./scripts/install.sh --auto-deploy
```

See [PERSONAL_SERVER.md](PERSONAL_SERVER.md) for maintainer workflow details.

---

## After install

Open **https://localhost:8000** (or your `APP_URL`). Production hardening (HTTPS, backups, mail): **[SELF_HOST.md](SELF_HOST.md)**.

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
| `php artisan app:install` | First install on an empty database |
| `php artisan app:update` | Production upgrades |

Inside Docker:

```bash
docker compose exec app php artisan app:update
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Vite manifest missing | `docker compose exec app npm ci && npm run build` |
| Missing tables | `docker compose exec app php artisan migrate` |
| `storage/` permissions | Ensure the container can write to `storage` and `bootstrap/cache` |
| Queues stuck | `docker compose restart worker` |
| Mail not sent | Configure `MAIL_*` (Mailpit is dev-only) |
