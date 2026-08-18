# Team access and roles

nrth authorizes day-to-day product features through **TeamAccess**, not Spatie team roles or Jetstream ability strings. How TeamAccess sits next to Eloquent `TeamScope`, actions, and HTTP: [ARCHITECTURE.md](ARCHITECTURE.md#team-scoping).

## Mental model

| Concept | Meaning |
|---------|---------|
| **Business** | Selected Jetstream team (`current_team`) |
| **Owner** | `teams.user_id` — full access; not a pivot role |
| **Role key** | `team_user.role` / invitation `role` (e.g. `accountant`, `viewer`, or a custom slug) |
| **Permissions** | Fixed catalog keys such as `invoices.manage` |
| **Custom role** | Per-business named subset of the catalog (`team_roles`, `is_system = false`) |

Resolution order for a member: belong to team → if owner, all permissions → else load `team_roles` for the membership key → system roles use [`RolePresets`](../app/Support/TeamAccess/RolePresets.php) at runtime → unknown key falls back to Viewer.

## Source files

| File | Role |
|------|------|
| [`PermissionCatalog.php`](../app/Support/TeamAccess/PermissionCatalog.php) | All permission keys, labels, UI groups |
| [`RolePresets.php`](../app/Support/TeamAccess/RolePresets.php) | Owner / Accountant / Viewer permission sets |
| [`TeamAccess.php`](../app/Support/TeamAccess/TeamAccess.php) | `allows()` / `permissionsFor()` |
| [`EnsureTeamSystemRoles.php`](../app/Support/TeamAccess/EnsureTeamSystemRoles.php) | Seed `accountant` / `viewer` rows per team |
| Controllers | `$this->authorizeTeam('…')` via `AuthorizesTeamAccess` |
| Inertia share | `team_permissions` in `HandleInertiaRequests` |
| Settings UI | Create/edit custom roles; assign on invite/member |

## Semantics

- **view** — list, show, PDF/download that only reads
- **manage** — create, update, send, and other non-destructive actions
- **delete** — destroy, void, and other destructive actions
- **export** — dedicated export endpoints (e.g. `reports.export`)

Owner-only product areas that stay outside (or beside) the matrix: tax takeout, instance backups (`manageInstanceBackups`). Instance settings (outbound SMTP, operators) use the same operator gate under **Settings → Instance**. Renaming or deleting a business stays Jetstream `TeamPolicy` (owner). Inviting members, changing roles, and custom role CRUD use `settings.team` (owner by default; grantable on a custom role). Business settings pages use `settings.business` the same way.

**Settings navigation:** Profile is always available. Business, Features, and Team members tabs appear only when the signed-in user has `settings.business` / `settings.team` respectively — viewers and accountants should not see those links (and get 403 if they hit the URLs directly). **Settings → Features** toggles optional modules (`team_modules`); Features uses `settings.business`. **Settings → Business** includes an optional per-business timezone (falls back to the instance default). **Settings → Instance → Timezone** (operators) sets that install-wide default. **Settings → Backups & exports** is shown to team owners (data takeouts) and instance operators (server backups); it is not in the main sidebar.

## Leaving a business

Non-owners who were invited into a business can leave it at any time:

1. **Settings → Profile** — “Leave business” (available without `settings.team`).
2. **Business switcher** (sidebar) — “Leave {business}” when the current business is not owned by the user.
3. **Team members** (owners / `settings.team`) — each non-owner row still has “Leave team” for themselves.

Leaving uses Jetstream `DELETE /teams/{team}/members/{user}` (self). Owners cannot leave a business they created. After leave, the member is switched to another owned/member business when one exists; otherwise they are sent to create a business.

## Invitations

Invited people should **join the existing business**, not create a new one:

1. Owner invites by email (role = `team_roles.key`).
2. The email has one button: **Join {business}** → signed `/invitations/{id}` (`team-invitations.join`). The link expires after **7 days**.
3. **New user** (no account): self-registration is disabled. The invite link sends them to sign in with a message to ask the instance administrator or business owner to create their account first.
4. **Existing user**: sign in with that email; login automatically accepts pending invites for a **verified** address and lands on the invited business (skips owner onboarding even if a leftover personal team exists). Changing your profile email requires your current password. A safe new address is re-verified; a new address that matches a pending invitation is left unverified so it does not auto-join.
5. Signed join/accept still matches on email. Middleware no longer auto-joins on every request.

## When adding a feature

See [AGENTS.md](../AGENTS.md) for the agent checklist (permissions **and** documentation). In short: extend the catalog, keep presets coherent, enforce in controllers, gate nav/CTAs, update Settings copy if needed, add a test, and update this doc if semantics or presets change.

## Built-in presets (intent)

- **Owner** — all catalog permissions
- **Accountant** — view and manage operational data (including `items.*` manage, not delete), export reports; cannot delete records or change business/team settings
- **Viewer** — read-only across domains that have `*.view` (including items)

Money In catalog keys include `invoices.*`, `estimates.*`, `clients.*`, and `items.*` (products/services catalog). Invoice **note templates** live under Settings → Note templates (`settings.business`). Item unit labels are under Settings → Business.

Banking catalog keys are `banking.view` and `banking.manage`. **View** covers imported transactions, accounts, and the reconciliation queue. **Manage** covers statement import, account setup, matching imported lines to posted payments/expenses/journal entries, split allocations, excluding personal/out-of-scope lines, and resetting a line to unreviewed. Not every imported line has to be matched — mixed personal/business accounts can leave personal activity excluded or unreviewed.

Travel catalog keys are `vehicles.view`, `vehicles.manage`, and `vehicles.delete` (vehicles registry and trip log book). Smart AI import (fleet/GPS exports) requires `vehicles.manage` and a team AI provider. Confirmed imports are tracked as batches; undo (remove all trips from that import) and multi-select bulk delete on the log book require `vehicles.delete`. Filtered CSV and PDF log book exports require `vehicles.view`. PDF export also requires a from/to date range and is capped (currently 1,500 trips) so DomPDF cannot exhaust request memory. Licence disc expiry reminders email team members who have `vehicles.view` (and have not opted out under Profile preferences).

Wealth catalog keys are `wealth.view` and `wealth.manage`. **Travel**, **Planning**, **Contracting**, and **Wealth** are optional modules (off by default for new businesses under Settings → Features). Existing businesses are opted in for Travel / Planning / Contracting by migration when upgrading. Disabling a module hides nav and returns 403 without deleting data. Travel covers vehicles and trip log book; Planning covers budgets; Contracting covers client contracts; Wealth covers portfolios, assets, valuation snapshots, cash-flow transactions, contribution allowances, and derived investment movement / history.

## Tests

Feature coverage lives under [`tests/Feature/TeamAccess/`](../tests/Feature/TeamAccess/). Prefer HTTP 403 assertions plus `TeamAccess::allows` for custom role matrices.

Cross-business isolation (owner of business B cannot open or mutate business A’s invoices, expenses, ledger, reports, VAT, or banking) lives under [`tests/Feature/TenantIsolation/`](../tests/Feature/TenantIsolation/). Report arithmetic on a known journal (P&L, trial balance, balance sheet, cash flow) lives under [`tests/Feature/Reports/`](../tests/Feature/Reports/).
