# Self-hosting nrth

Day-to-day install and updates: **[INSTALL.md](INSTALL.md)** (two commands). This page covers HTTPS, backups, and recovery.

For laptop development: [DEVELOPMENT.md](DEVELOPMENT.md).

---

## HTTPS

nrth is a financial app — do not expose plain HTTP long-term.

- Octane serves **HTTP on port 8000** inside Docker (normal).
- Browsers should use **HTTPS on 443** via Compose Caddy (`--production`) or a host reverse proxy.
- Set `APP_URL` to the public `https://…` URL (**no** `:8000` when using Caddy on 443).
- Never open `https://host:8000` — nothing speaks TLS on that port.
- `--lan` / HTTP on `:8000` is **trusted private network only**. Do not port-forward the app (or Postgres/Redis/Mailpit) to the internet.
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
2. HTTPS on 443; firewall only 80/443 (not `:8000`, and not Postgres/Redis/Mailpit)
3. Real `MAIL_*` (Mailpit is for testing — do not expose `:8025` publicly)
4. Host-level backups of Postgres + `storage` volumes, plus in-app instance backups
5. Never commit `.env`

---

## Backups

Laravel schedules `backup:run` (03:00) and `backup:clean` (03:30). The first admin is an **instance operator** — manage operators and runs under **Backups & exports → Instance backup**.

| | Data takeout | Instance backup |
|--|--------------|-----------------|
| Who | Team owner | Instance operator |
| What | Period tax/audit zip | Whole install (DB + files) |
| Restore | N/A | CLI via **Backups & exports → Instance restore guide** (generated script; not one-click in-app) |

Optional break-glass: `NRTH_OPERATOR_EMAILS` in `.env`. Existing installs with no operators: `./scripts/compose.sh exec app php artisan nrth:promote-first-operator` (also run by `./scripts/update`).

### Restore (CLI)

There is no in-app one-click restore. On **Backups & exports → Instance backup**, operators can open **Instance restore guide**, pick a ready zip, and copy/download a shell script for `./scripts/compose.sh` (self-host) or Sail. The script extracts the dump, stops app services, replaces the Postgres database, then starts services again. Review the script before running — it replaces the live database.

### Optional AI

Configure under **Business settings → AI** (provider, model, API key, and base URL where needed).

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

```bash
cd /opt/nrth
./scripts/update
```

Data-safe: no volume wipe, incremental migrate only. See [INSTALL.md](INSTALL.md).

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Won’t start / wrong URL / HTTPS | `./scripts/repair.sh --ip YOUR_IP` |
| Wipe and reinstall | `./scripts/reset.sh --force` |
| `https://IP:8000` fails | Use `https://IP/` (Caddy) or temporary `http://IP:8000` with `--lan` |
| Docker permission denied | `./scripts/compose.sh …` or `newgrp docker` |
| Vite manifest missing | `./scripts/compose.sh exec app npm ci && npm run build` |
| Queues stuck | `./scripts/compose.sh restart worker` |
| Backup dump process failed | Image `pg_dump` must match Postgres major (compose uses 16). Rebuild: `./vendor/bin/sail build --no-cache` then `./vendor/bin/sail up -d` |
| Backup / takeout log permission denied | `./vendor/bin/sail up -d --force-recreate worker app scheduler` then retry. Entrypoint makes `storage` world-writable; Horizon runs as root like Octane. |
| Backup stuck “already running” | `./vendor/bin/sail artisan cache:clear` then retry |
| DB password mismatch | `./scripts/repair.sh` |

Useful:

```bash
./scripts/compose.sh ps
./scripts/compose.sh logs -f app
./scripts/compose.sh down          # stop containers, keep data
./scripts/compose.sh down -v --force   # destroy volumes
```
