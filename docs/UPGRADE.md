# Upgrading nrth

Self-hosted instances upgrade in place. Data volumes are kept. Pending Laravel migrations are applied incrementally — the updater does **not** run `migrate:fresh` or wipe Postgres.

Day-to-day install: **[INSTALL.md](INSTALL.md)**. HTTPS and backups: **[SELF_HOST.md](SELF_HOST.md)**.

The signed-in sidebar (and the sign-in screen) show the installed version from `version.txt`. When this instance can reach GitHub, a newer [GitHub Release](https://github.com/mortolian/nrth/releases) also appears there (and on **Settings → Instance**). Air-gapped hosts can set `NRTH_RELEASE_CHECK=false`.

---

## Before you upgrade

1. Take an **instance backup**: `./scripts/backup` (same zip as Settings → Backups & exports). A host-level Postgres dump plus `storage` also works.
2. Read the [CHANGELOG](../CHANGELOG.md) for the tag you are moving to.
3. Plan a rollback: restore that backup, then check out the previous tag (see below).

You do not need to upgrade through every intermediate tag. Migrations are sequential; skipping `v0.1.2` → `v0.1.4` is fine as long as you run `./scripts/update` once on the newer tree.

---

## Happy path (follow the default branch)

From the install directory (usually `/opt/nrth`):

```bash
cd /opt/nrth
./scripts/backup && ./scripts/update
```

That takes a backup of the live instance first, then pulls `origin/master` (override with `GIT_BRANCH`), rebuilds assets, runs pending migrations, refreshes caches, and restarts the app. With `APP_ENV=production` it uses `php artisan app:update` (maintenance mode).

`./scripts/update` does **not** take a backup on its own. `./scripts/deploy.sh` is an alias of update only.

---

## Upgrade to a specific release tag

Prefer tags for a production-minded instance. Tags are created by [Release Please](RELEASE.md) (`v0.x.y`).

```bash
cd /opt/nrth
./scripts/backup && ./scripts/update --ref v0.1.3
```

Equivalent: `GIT_REF=v0.1.3 ./scripts/update`.

`--ref` accepts a **tag**, **branch**, or **commit**. The database is not replaced; only pending migrations on that tree run.

List tags: `git fetch --tags && git tag -l 'v*'`

---

## What the updater does

| Step | Effect on data |
|------|----------------|
| `git reset --hard` / checkout `--ref` | Code tree only |
| `composer install` / `npm run build` | Dependencies and frontend |
| `php artisan nrth:upgrade-status` | Shows version + pending migrations |
    | `php artisan nrth:move-media-to-private-disk` | Moves invoice PDFs and receipts off the public disk (also run by `./scripts/update`) |
| `migrate --force` (or `app:update`) | Incremental schema only |
| Cache rebuild + Octane restart | No volume wipe |

Inspect pending migrations any time:

```bash
./scripts/compose.sh exec app php artisan nrth:upgrade-status
```

---

## Rollback

1. Restore the instance backup taken before the upgrade ([SELF_HOST.md](SELF_HOST.md) — Restore). Restoring replaces the live database.
2. Point the code tree at the previous tag:

```bash
./scripts/update --ref v0.1.2
```

Do not “roll back” by deleting migration files or running `migrate:fresh` on a live install.

---

## Schema policy (from 2026-08-18)

New migrations on existing tables are **additive**: new tables, columns, and indexes. `up()` must not `dropColumn`, `renameColumn`, or drop tables.

Older alpha tags may still have included breaking migrations. From this cutoff forward, a normal `./scripts/update` between tags should not destroy existing money, banking, or team data.

If a future change cannot be additive, it must use an expand/contract pair and be called out as breaking in the release notes.

Contributors: see [CONTRIBUTING.md](../CONTRIBUTING.md).

---

## Tracking master vs tags

| Track | Use when |
|-------|----------|
| `./scripts/update` (default `master`) | You want the latest development tree. Beta-labelled; `master` can still move quickly. Prefer a tag for live books. |
| `./scripts/update --ref v0.x.y` | You want a known GitHub Release. Recommended for a live books instance. |

`1.0` is not promised until data models, this upgrade path, and core workflows stay stable enough for production-minded self-hosters ([ROADMAP.md](ROADMAP.md)).

---

## Foreign-currency ledger repair

Releases that post invoice accruals in **book currency** (team `invoice_default_currency`) may leave older foreign-currency invoices with AR/revenue lines still in invoice currency (e.g. EUR cents labelled as Rand on statements). After upgrading:

1. Backup first (`./scripts/backup`).
2. Dry-run for the affected team:

```bash
php artisan invoicing:repair-foreign-ledger --team=YOUR_TEAM_ID
```

3. Review the table. Invoices missing an FX snapshot are skipped — open and save those invoices so a rate is stored, then re-run.
4. Apply:

```bash
php artisan invoicing:repair-foreign-ledger --team=YOUR_TEAM_ID --apply
```

The command voids mismatched accrual/payment journals and rebuilds them from the invoice snapshot and payment rows. Invoice paid totals and payment rows are kept.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Updater discarded local code edits | Expected (`git reset --hard`). Data volumes are untouched. Commit or stash first. |
| `./scripts/backup` failed | Do not run `./scripts/update` until a backup is Ready. Check Horizon / Settings → Backups & exports. |
| UI looks old | Re-run `./scripts/update` so assets rebuild and `public/hot` is removed |
| Need to undo the schema | Restore the pre-upgrade backup; do not hand-edit `migrations` |
| `HEAD does not match origin/master` | You are on a tag (`--ref`). That check is skipped for `--ref`. To return to master, run `./scripts/update` without `--ref`. |
| Statement shows foreign amounts as Rand 1:1 | Run `invoicing:repair-foreign-ledger` (see above) after upgrading to book-currency accruals. |
