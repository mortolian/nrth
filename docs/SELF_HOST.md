# Self-hosting nrth

Day-to-day install and updates: **[INSTALL.md](INSTALL.md)**. This page covers HTTPS, instance backup, restore, and recovery.

For laptop development: [DEVELOPMENT.md](DEVELOPMENT.md).

---

## HTTPS

nrth is a financial app — do not expose plain HTTP long-term.

- Octane serves **HTTP on port 8000** inside Docker (normal).
- Browsers should use **HTTPS on 443** via Compose Caddy (`--production`) or a host reverse proxy.
- Set `APP_URL` to the public `https://…` URL (**no** `:8000` when using Caddy on 443).
- Never open `https://host:8000` — nothing speaks TLS on that port.
- `--lan` / HTTP on `:8000` is **trusted private network only**. Do not port-forward the app to the internet.
- Do not publish Octane `:8000` on a public interface. The default `TRUSTED_PROXIES=*` trusts `X-Forwarded-*` from any client; only Caddy (or your reverse proxy) should be able to reach port 8000.
- Postgres and Redis host ports bind to `127.0.0.1` by default. Vite HMR and Mailpit are dev-only services behind the optional `dev` Compose profile.
- Production: do not publish Postgres, Redis, or Mailpit on `0.0.0.0`; firewall should allow **80/443 only** to the host.

### LAN IP (self-signed)

```env
COMPOSE_PROFILES=proxy
APP_URL=https://192.168.1.204
CADDY_SITE=192.168.1.204
CADDY_TLS=internal
```

Then `./scripts/compose.sh up -d`. Accept the browser certificate warning.

### Public domain (Let's Encrypt)

```env
COMPOSE_PROFILES=proxy
APP_URL=https://books.example.com
CADDY_SITE=books.example.com
CADDY_TLS=off
```

Port 80 must be reachable for ACME. Temporary plain HTTP for private LAN only: see [INSTALL.md](INSTALL.md) `--lan`.

---

## Production checklist

1. `APP_DEBUG=false`, `APP_ENV=production`
2. Set the instance default under **Settings → Instance → Timezone** (or `APP_TIMEZONE` in `.env` as the install fallback, e.g. `Africa/Johannesburg`). Each business can override under **Settings → Business**. Schedules and backups use the instance default; the sidebar clock uses the current business timezone (or the instance default when unset).
3. HTTPS on 443; firewall only 80/443 (not `:8000`, and not Postgres/Redis/Mailpit)
4. Outbound email: configure SMTP under **Settings → Instance → Outbound email**, or set `MAIL_*` in `.env` (Mailpit is for testing — do not expose `:8025` publicly). Instance SMTP overrides `.env` when enabled (applied on each Octane request and Horizon job — no worker restart required after saving). Use a **From** address on a domain your provider has verified (not `example.com`); that From is also used for instance backup status emails, which go to instance operators. A backup zip is still marked Ready if the status email fails to send.
5. Instance backups (`./scripts/backup`) plus a host-level snapshot of Postgres + `storage` volumes — see [Backups and restore](#backups-and-restore)
6. Never commit `.env`
7. Leave `PAYFAST_SANDBOX` unset or `false` on a live host (sandbox ITNs can mark invoices paid). `MEDIA_DISK` should stay `local` (private) unless you have configured private object storage.

---

## Backups and restore

Instance backups are [Spatie Laravel Backup](https://spatie.be/docs/laravel-backup) (`config/backup.php`) wrapped as `nrth:backup-run`. Use **`./scripts/backup`** or **Settings → Backups & exports** so the run is tracked, typed, and rotated. Do not call `php artisan backup:run` by hand on a live install.

The first admin is an **instance operator**. Manage backups under **Settings → Backups & exports → Instance backup**, and operators under **Settings → Instance → Operators**. Optional break-glass: `NRTH_OPERATOR_EMAILS` in `.env`. Existing installs with no operators: `./scripts/compose.sh exec app php artisan nrth:promote-first-operator` (also run by `./scripts/update`).

Keep the Compose **`scheduler`** service running (or an equivalent cron for `php artisan schedule:run`). It also runs recurring invoices (`01:30`) and licence-disc reminder emails (`01:15`).

| | Data takeout | Instance backup |
|--|--------------|-----------------|
| Who | Team owner | Instance operator |
| What | Period tax/audit zip | Whole install (Postgres + app files) |
| Restore | N/A | CLI via **Settings → Backups & exports → Instance restore guide** (no one-click in-app restore) |

### Take a backup

Before every live upgrade:

```bash
cd /opt/nrth
./scripts/backup && ./scripts/update
```

| How | When |
|-----|------|
| `./scripts/backup` | On demand; waits until the zip is Ready. Does not pull git or migrate. |
| `./scripts/backup --queue` | Enqueue only (Horizon must be running) |
| Settings → Backups & exports | Same job as the script; queued on Horizon’s `long` queue |
| Nightly `nrth:backup-run` at **03:00** | Automatic when `scheduler` is up |

`./scripts/update` does **not** take a backup on its own.

### What is in the zip

Each zip is a full instance snapshot:

- A Postgres dump (`db-dumps/*.sql`) via `pg_dump` with `--no-owner --no-acl`. The client in the app image must match Compose Postgres **16**.
- Application files from the project tree. Spatie excludes `vendor`, `node_modules`, and `storage/framework`. Uploads under `storage/app/private` are included.
- Local zips are written to **`storage/app/private/{APP_NAME}/`** (default `APP_NAME=nrth` → `storage/app/private/nrth/*.zip`). That directory lives on the Compose `storage_data` volume.

A host-level snapshot of the Postgres volume (`mysql_data`) plus `storage_data` is still a good extra, especially before risky host work. It is not a substitute for a Ready instance zip when you want the in-app restore guide.

### Encryption and status email

| Setting | Purpose |
|---------|---------|
| `BACKUP_ARCHIVE_PASSWORD` in `.env` | Optional AES password for the zip. Store it off-host too — without it, restore cannot unzip. After changing it, refresh config (`./scripts/update` or `php artisan config:cache`). The restore guide notes when encryption is on. |
| Instance SMTP (**Settings → Instance → Outbound email**) | From address for backup status mail. A zip is still marked Ready if the email fails. |
| Recipients | Instance operators. `BACKUP_NOTIFICATION_EMAIL` is only the Spatie fallback when no operators are configured. Use a verified From domain (not `example.com`). |

You do not need to edit `config/backup.php` for day-to-day operation. Offsite disks and retention are set in the UI; the scheduler runs **`nrth:backup-rotate`**, not Spatie’s `backup:clean`.

### Offsite destinations

Backups are always written to the local disk first. Operators can also mirror each zip under **Settings → Backups & exports → Offsite destinations**:

- **S3-compatible** — AWS S3, Cloudflare R2, MinIO, etc. Credentials are stored encrypted in instance settings (no `.env` `AWS_*` required for backups when using the UI). Use **Test S3** after saving.
- **Path / NFS** — an absolute path **inside the container**. Mount the share into `app`, `horizon`, and `scheduler`, for example:

```yaml
volumes:
  - /mnt/nas/nrth-backups:/mnt/backups
```

Then set the path to `/mnt/backups` in the UI and use **Test path**.

Rotation and manual delete remove the zip from **every** configured destination. Downloads and the restore guide use the **local** copy — copy an offsite zip back into `storage/app/private/{APP_NAME}/` if you only have the offsite file.

### Retention

Each daily zip can also count as weekly (configurable weekday), monthly (month-end), and yearly (31 Dec). **Settings → Backups & exports → Backup retention** sets how many of each type to keep; `nrth:backup-rotate` at **03:30** deletes zips that no type still needs. An optional size cap is also available.

### Restore (CLI)

There is no in-app one-click restore — it would overwrite the live database while the app is running.

1. Confirm a **Ready** zip exists locally (download it from the UI, or copy it back from offsite).
2. Open **Settings → Backups & exports → Instance restore guide** (or **Restore guide** on a ready row).
3. Pick the zip and runtime (`./scripts/compose.sh` for self-host, Sail for laptop). Copy or download the generated script.
4. Review it. It extracts the SQL dump, stops app services (Postgres stays up), **drops and recreates** the database, imports the dump, then starts services again. Uploaded files are an optional extra step printed at the end.
5. If you are rolling back an upgrade, restore data first, then point code at the previous tag: `./scripts/update --ref v0.x.y` ([UPGRADE.md](UPGRADE.md)).

App code still comes from git. The zip is not a full OS image.

### Optional AI

Configure under **Business settings → AI**. AI is **off by default** — enable it there, then set provider, model, API key, and base URL where needed.

Supported providers:

- OpenAI, Anthropic, Google Gemini
- OpenRouter
- OpenAI-compatible (custom base URL — use this for Ollama and other local/OpenAI-compatible servers)

Used by features such as expense receipt autofill (**Scan receipt**). Cloud providers need an API key. OpenAI-compatible can run with a base URL and an optional key (e.g. Ollama at `http://127.0.0.1:11434/v1`). Local vision models typically need images rather than PDFs.

Optional server-wide fallback in `.env`:

- `AI_PROVIDER=openai` (or `anthropic`, `gemini`, `openrouter`, `openai_compatible`)
- `OPENAI_API_KEY` / `OPENAI_MODEL`
- `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL`
- `GEMINI_API_KEY` / `GEMINI_MODEL`
- `OPENROUTER_API_KEY` / `OPENROUTER_MODEL` / `OPENROUTER_BASE_URL`
- `OPENAI_COMPATIBLE_API_KEY` / `OPENAI_COMPATIBLE_MODEL` / `OPENAI_COMPATIBLE_BASE_URL`

Env values are used only when the business setting is empty.

---

## Update

Tag-to-tag upgrades, backups, and rollback: **[UPGRADE.md](UPGRADE.md)**.

```bash
cd /opt/nrth
./scripts/backup && ./scripts/update
# or pin a release: ./scripts/backup && ./scripts/update --ref v0.1.3
```

Data-safe: no volume wipe, incremental migrate only. The updater rebuilds `public/build`,
removes dev-only Compose services, removes any stale `public/hot`, and restarts Octane so
deployed browsers use the freshly built versioned assets. Vite HMR and Mailpit are behind the
optional `dev` profile, so self-hosted stacks do not start them unless you opt in. See
[INSTALL.md](INSTALL.md).

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Won’t start / wrong URL / HTTPS | `./scripts/repair.sh --ip YOUR_IP` |
| Wipe and reinstall | `./scripts/reset.sh --force` |
| `https://IP:8000` fails | Use `https://IP/` (Caddy) or temporary `http://IP:8000` with `--lan` |
| UI still looks old after update | Re-run `./scripts/update` on the latest code so it rebuilds assets, removes dev-only services, and removes any stale `public/hot` file |
| Docker permission denied | `./scripts/compose.sh …` or `newgrp docker` |
| Vite manifest missing | `./scripts/compose.sh exec app npm ci && npm run build` |
| Queues stuck | `./scripts/compose.sh restart horizon` |
| Backup dump process failed | Image `pg_dump` must match Postgres **16**. Self-host: `./scripts/compose.sh build --no-cache && ./scripts/compose.sh up -d`. Local Sail: `./vendor/bin/sail build --no-cache` then `up -d` |
| Backup / takeout log permission denied | Recreate app services so the entrypoint can fix `storage` permissions: `./scripts/compose.sh up -d --force-recreate horizon app scheduler` |
| `./scripts/backup` failed / still queued | Default `./scripts/backup` waits in the `app` container (no Horizon). `--queue` and the UI need Horizon. Check **Settings → Backups & exports**. A queued/processing run older than 75 minutes is marked failed automatically. |
| Restore unzip fails | If `BACKUP_ARCHIVE_PASSWORD` is set, decrypt with that password. Copy an offsite-only zip back to `storage/app/private/{APP_NAME}/` first. |
| DB password mismatch | `./scripts/repair.sh` |

Useful:

```bash
./scripts/compose.sh ps
./scripts/compose.sh logs -f app
./scripts/compose.sh down          # stop containers, keep data
./scripts/compose.sh down -v --force   # destroy volumes
```
