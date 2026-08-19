# Agent instructions (nrth)

This file is for **any** coding agent (Cursor, Copilot, Codex, Claude Code, etc.). Prefer it over tool-specific rule formats when they conflict on project conventions.

Also follow [CONTRIBUTING.md](CONTRIBUTING.md), [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md), and conventional commits in [docs/RELEASE.md](docs/RELEASE.md).

## Documentation (required when behavior or setup changes)

Docs are part of the product surface for self-hosters and contributors. When you change user-visible behavior, install/setup, configuration, auth/roles, or release process, **update the matching docs in the same change** (or the same PR). Do not leave README/`docs/` describing the old flow.

### Which file to update

| If you changed… | Update… |
|-----------------|---------|
| Features list / project pitch | [README.md](README.md) |
| Local PHP/Node or Sail workflow | [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) |
| Installer flags, first-run, env vars for operators | [docs/INSTALL.md](docs/INSTALL.md), [.env.example](.env.example) |
| Tag-to-tag upgrades / migrate story | [docs/UPGRADE.md](docs/UPGRADE.md), [docs/INSTALL.md](docs/INSTALL.md) |
| Docker / Compose / production self-host | [docs/SELF_HOST.md](docs/SELF_HOST.md) (HTTPS, backups, multi-tenant hardening), [docs/PERSONAL_SERVER.md](docs/PERSONAL_SERVER.md) if relevant |
| Versioning, changelog, Release Please | [docs/RELEASE.md](docs/RELEASE.md) |
| Team roles / permissions | [docs/TEAM_ACCESS.md](docs/TEAM_ACCESS.md) (and this file’s checklist below) |
| Domain layout / request flow | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Contributing / PR expectations | [CONTRIBUTING.md](CONTRIBUTING.md) |
| Security reporting or trust boundaries | [SECURITY.md](SECURITY.md) |

Add a new `docs/*.md` only when no existing doc fits; link it from README, INSTALL, or CONTRIBUTING so it is discoverable.

### Checklist

1. **Accuracy** — Commands, routes, env keys, and role names match the code you shipped.
2. **Discoverability** — New entry-point docs are linked from README or INSTALL; avoid orphan pages.
3. **Scope** — Prefer updating the canonical doc over duplicating the same instructions in three places; cross-link instead.
4. **Commit type** — Docs-only follow-ups use `docs:`; feature work that includes doc updates stays `feat:` / `fix:` with docs in the same commit when practical.
5. **No speculative docs** — Do not document unbuilt features as if they exist (use ROADMAP if needed).

### Out of scope unless asked

- Marketing site / landing copy outside this repo
- Regenerating changelog by hand when Release Please owns it ([docs/RELEASE.md](docs/RELEASE.md))

## Team access permissions (required when features change)

Team authorization is a first-class product surface. When you add or change authenticated app features, **keep roles and permissions current** in the same change (or an immediately following commit in the same PR). Do not ship new screens/APIs that only check “belongs to team.”

Canonical docs: [docs/TEAM_ACCESS.md](docs/TEAM_ACCESS.md).

### Checklist (do all that apply)

1. **Catalog** — Add or adjust keys in [`app/Support/TeamAccess/PermissionCatalog.php`](app/Support/TeamAccess/PermissionCatalog.php) (`resource.view` / `resource.manage` / `resource.delete` / `*.export` as needed). Labels and groups drive the custom-role UI.
2. **Presets** — Confirm [`RolePresets`](app/Support/TeamAccess/RolePresets.php) still match product intent. Viewer = `*.view` only; Accountant = view + manage (+ `reports.export`), no `*.delete`, no `settings.*`; Owner = all via `ownsTeam()`. Prefer deriving presets from the catalog so new `*.view` keys are not forgotten.
3. **Enforce** — Call `$this->authorizeTeam('…')` (or `User::canOnTeam` / `TeamAccess::allows`) on every relevant Web controller action. Keep existing `team_id` checks.
4. **UI** — Hide nav items / primary create CTAs when the matching permission is missing ([`AppLayout.vue`](resources/js/Components/layout/AppLayout.vue), page headers, command palette in [`HandleInertiaRequests`](app/Http/Middleware/HandleInertiaRequests.php)). Server remains authoritative.
5. **Copy** — Update role descriptions in Settings / Jetstream registration if the meaning of Accountant or Viewer changed.
6. **Tests** — Extend [`tests/Feature/TeamAccess/`](tests/Feature/TeamAccess/) for new gates (at least one deny for Viewer or a custom role).
7. **Docs** — Keep [docs/TEAM_ACCESS.md](docs/TEAM_ACCESS.md) aligned if catalog semantics or presets change.

Custom roles store permission key lists in `team_roles`. New catalog keys do not appear on existing custom roles until an owner edits that role — that is expected. Built-in system roles resolve from `RolePresets` at runtime.

### Out of scope unless asked

- Per-person permission overrides
- Spatie `ledger.*` / Jetstream ability strings as product auth (legacy; do not extend for new features)
- Public/webhook routes and instance-operator gates (separate from team roles)
