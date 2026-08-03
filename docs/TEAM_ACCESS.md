# Team access and roles

nrth authorizes day-to-day product features through **TeamAccess**, not Spatie team roles or Jetstream ability strings.

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

Owner-only product areas that stay outside (or beside) the matrix: tax takeout, instance backups / operators (`manageInstanceBackups`). Renaming or deleting a business stays Jetstream `TeamPolicy` (owner). Inviting members, changing roles, and custom role CRUD use `settings.team` (owner by default; grantable on a custom role). Business settings pages use `settings.business` the same way.

**Settings navigation:** Profile is always available. Business and Team members tabs appear only when the signed-in user has `settings.business` / `settings.team` respectively — viewers and accountants should not see those links (and get 403 if they hit the URLs directly).

## Leaving a business

Non-owners who were invited into a business can leave it at any time:

1. **Settings → Profile** — “Leave business” (available without `settings.team`).
2. **Business switcher** (sidebar) — “Leave {business}” when the current business is not owned by the user.
3. **Team members** (owners / `settings.team`) — each non-owner row still has “Leave team” for themselves.

Leaving uses Jetstream `DELETE /teams/{team}/members/{user}` (self). Owners cannot leave a business they created. After leave, the member is switched to another owned/member business when one exists; otherwise they are sent to create a business.

## Invitations

Invited people should **join the existing business**, not create a new one:

1. Owner invites by email (role = `team_roles.key`).
2. The email has one button: **Join {business}** → signed `/invitations/{id}` (`team-invitations.join`).
3. **New user** (no account): self-registration is disabled. The invite link sends them to sign in with a message to ask the instance administrator or business owner to create their account first.
4. **Existing user**: sign in with that email; login automatically accepts pending invites and lands on the invited business (skips owner onboarding even if a leftover personal team exists).
5. Middleware / onboarding also settle pending invites and prefer membership on another business over unfinished personal-team setup.

## When adding a feature

See [AGENTS.md](../AGENTS.md) for the agent checklist (permissions **and** documentation). In short: extend the catalog, keep presets coherent, enforce in controllers, gate nav/CTAs, update Settings copy if needed, add a test, and update this doc if semantics or presets change.

## Built-in presets (intent)

- **Owner** — all catalog permissions
- **Accountant** — view and manage operational data (including `items.*` manage, not delete), export reports; cannot delete records or change business/team settings
- **Viewer** — read-only across domains that have `*.view` (including items)

Money In catalog keys include `invoices.*`, `estimates.*`, `clients.*`, and `items.*` (products/services catalog). Invoice **note templates** live under Settings → Note templates (`settings.business`). Item unit labels are under Settings → Business.

Travel catalog keys are `vehicles.view`, `vehicles.manage`, and `vehicles.delete` (vehicles registry and trip log book).

## Tests

Feature coverage lives under [`tests/Feature/TeamAccess/`](../tests/Feature/TeamAccess/). Prefer HTTP 403 assertions plus `TeamAccess::allows` for custom role matrices.
