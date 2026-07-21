# Personal server (maintainer)

**Maintainer-only.** Run nrth on a home/office Docker host for fast iteration.

Self-host users: follow [INSTALL.md](INSTALL.md) — same install and `./scripts/update` commands.

**Security:** the default below is `--lan` (plain HTTP on `:8000`). That is fine only on a **trusted private LAN**. It is not secure on the public internet. Compose may also publish Postgres, Redis, and Mailpit on the host — keep those off the internet (no port-forward; firewall if unsure). For HTTPS on the same box, switch to the Caddy/`proxy` setup in [SELF_HOST.md](SELF_HOST.md).

---

## One-time setup

```bash
curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh \
  | sudo bash -s -- --accept-data-risk --lan --install-dir /opt/nrth
```

Optional push-to-deploy runner:

```bash
GITHUB_RUNNER_TOKEN=<token> ./scripts/install.sh --auto-deploy --install-dir /opt/nrth
```

Register the runner with label **`nrth-server`** (the installer does this). Workflow: [.github/workflows/deploy-server.yml](../.github/workflows/deploy-server.yml).

Bookmark: `http://<server-ip>:8000` only while on a trusted LAN. Prefer an HTTPS URL once Caddy is enabled ([SELF_HOST.md](SELF_HOST.md)).

---

## Day to day

**Manual:**

```bash
cd /opt/nrth
./scripts/update
```

**Automatic:** push to `master` → Actions job on the self-hosted runner runs `./scripts/update`.

No image rebuild for normal PHP/Vue changes (bind-mounted source + Octane watch). Lockfile changes trigger composer/npm inside `update`.

---

## Data safety

`./scripts/update` and re-runs of `install.sh` preserve volumes and the database. Destructive only if you run `reset.sh --force` or `compose.sh down -v --force`.
