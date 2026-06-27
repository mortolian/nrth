# nrth — Open Source & Community Readiness Checklist

This document consolidates all open TODO items and recommendations from planning to open-source **nrth** and make it community-ready. Use it as a living checklist before and after the public launch.

**Repository:** [mortolian/nrth](https://github.com/mortolian/nrth)

---

## Quick launch checklist

Minimum items to complete **before** making the repository public:

- [ ] Set GitHub repo **About** description, website URL, and **topics**
- [ ] Upload **social preview image** (Settings → General → Social preview)
- [ ] Create first **GitHub Release** (`v0.1.0` or later) with [CHANGELOG](../CHANGELOG.md) notes
- [ ] Add **screenshots or demo** to [README.md](../README.md) ([images/](images/))
- [ ] Enable **GitHub Discussions**
- [ ] Enable **private vulnerability reporting** (Settings → Security → Private vulnerability reporting)
- [ ] Review [deploy-personal-server.yml](../.github/workflows/deploy-personal-server.yml) visibility or document as maintainer-only
- [ ] Expand [CHANGELOG.md](../CHANGELOG.md) beyond the initial `0.1.0` entry

---

## Already completed

Community and documentation foundations are in place:

- [x] [LICENSE](../LICENSE) (MIT)
- [x] [CONTRIBUTING.md](../CONTRIBUTING.md)
- [x] [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md)
- [x] [SECURITY.md](../SECURITY.md)
- [x] GitHub issue templates — [bug_report.yml](../.github/ISSUE_TEMPLATE/bug_report.yml), [feature_request.yml](../.github/ISSUE_TEMPLATE/feature_request.yml), [config.yml](../.github/ISSUE_TEMPLATE/config.yml)
- [x] PR template — [.github/pull_request_template.md](../.github/pull_request_template.md)
- [x] [README.md](../README.md) restructure (badges, alpha notice, features, quick start)
- [x] [DEVELOPMENT.md](DEVELOPMENT.md) (dev details moved from README)
- [x] [INSTALL.md](INSTALL.md) updated with community doc links
- [x] Clone URLs fixed to `mortolian/nrth` in docs
- [x] Hosting guides: [SELF_HOST.md](SELF_HOST.md), [PERSONAL_SERVER.md](PERSONAL_SERVER.md), [scripts/install.sh](../scripts/install.sh), [scripts/deploy.sh](../scripts/deploy.sh)

---

## Priority 1 — Before public launch

GitHub presence, first release, and visible polish.

- [ ] **GitHub repo description + topics** — Set in Settings → General → About. Suggested topics: `laravel`, `accounting`, `invoicing`, `vue`, `south-africa`, `self-hosted`, `bookkeeping`, `saas`
- [ ] **Social preview image** — Logo + tagline (1200×630 px recommended). Upload under Settings → General → Social preview
- [ ] **First GitHub Release** — Tag `v0.1.0` (or current version) with release notes drawn from [CHANGELOG.md](../CHANGELOG.md)
- [ ] **Screenshots or demo in README** — Add assets under [images/](images/) and reference them in [README.md](../README.md)
- [ ] **Enable GitHub Discussions** — Settings → General → Features → Discussions (for Q&A, ideas, and community support)
- [ ] **Enable private vulnerability reporting** — Settings → Security → Private vulnerability reporting (complements [SECURITY.md](../SECURITY.md) GitHub Advisories link)
- [ ] **Optional: verify security contact email** — [SECURITY.md](../SECURITY.md) lists `security@mortolian.com`; confirm the inbox is monitored or update the address
- [ ] **Review deploy-personal-server workflow** — [.github/workflows/deploy-personal-server.yml](../.github/workflows/deploy-personal-server.yml) deploys to a self-hosted runner on push to `master`. Either restrict visibility (maintainer-only docs), add a workflow guard, or document clearly that this is for the maintainer's personal server only (see [PERSONAL_SERVER.md](PERSONAL_SERVER.md))
- [ ] **Expand CHANGELOG.md** — [CHANGELOG.md](../CHANGELOG.md) should cover more than the initial `0.1.0` entry; keep it updated for each release ([update-changelog.yml](../.github/workflows/update-changelog.yml) may assist)

---

## Priority 2 — Documentation

Deeper docs for contributors and self-hosters.

- [ ] **docs/ARCHITECTURE.md** — Document domains, actions pattern, team scoping ([app/Domain/](../app/Domain/), [TeamScope](../app/Domain/Shared/Scopes/TeamScope.php)), and high-level request/data flow
- [ ] **docs/ROADMAP.md** — Planned features, alpha scope, and what is explicitly out of scope for now
- [ ] **.env.example production warnings** — Add prominent comments for default passwords and secrets ([.env.example](../.env.example): `MINIO_ROOT_PASSWORD`, `DB_PASSWORD`, `APP_KEY`, etc.)
- [ ] **Spatie Backup documentation** — Document [config/backup.php](../config/backup.php) and backup/restore steps more prominently in [SELF_HOST.md](SELF_HOST.md) or [INSTALL.md](INSTALL.md) if self-hosters need it
- [ ] **Multi-tenant security note** — Add guidance for self-hosters on team isolation, registration settings, and hardening a multi-tenant deployment (relevant to Jetstream teams model)

---

## Priority 3 — Trust & polish

Security, licensing, and optional infrastructure improvements.

- [ ] **Security review** — Audit auth flows, file uploads, and payment webhooks (Stripe / PayFast: [PayFastPaymentWebhookController](../app/Http/Controllers/Web/Webhooks/PayFastPaymentWebhookController.php), related invoicing payment tests)
- [ ] **Composer / dependency license scan in CI** (optional) — Add a license compliance check to [.github/workflows/](../.github/workflows/) (e.g. `composer licenses` or a dedicated action)
- [ ] **Publish Docker image to GHCR** (optional) — Simplify self-hosting; reference from [SELF_HOST.md](SELF_HOST.md) and [compose.yaml](../compose.yaml)
- [ ] **Hosted docs site** (optional) — e.g. GitHub Pages or Read the Docs for [docs/](.)
- [ ] **Alpha/beta disclaimer review** — If a public demo exists, ensure disclaimers match [README.md](../README.md) alpha notice and data-handling expectations

---

## Priority 4 — GitHub settings (manual)

One-time or ongoing repository configuration in the GitHub UI.

- [ ] Enable **Discussions** (Settings → General → Features)
- [ ] Set repository **About**: short description + website (project site or docs)
- [ ] Add **topics** (see Priority 1 for suggestions)
- [ ] Upload **social preview image**
- [ ] Create **first GitHub Release** from an annotated tag
- [ ] Consider **all-contributors** or other contributor recognition later (not required for launch)

---

## Hosting & deployment (public repo notes)

One install script for everyone; deploy script for updates.

| Audience | Document | Scripts / automation |
| -------- | -------- | -------------------- |
| **Community / self-hosters** | [SELF_HOST.md](SELF_HOST.md) | [scripts/install.sh](../scripts/install.sh) |
| **Maintainer personal server** | [PERSONAL_SERVER.md](PERSONAL_SERVER.md) | `install.sh --auto-deploy`, [scripts/deploy.sh](../scripts/deploy.sh), [.github/workflows/deploy-personal-server.yml](../.github/workflows/deploy-personal-server.yml) |

- [ ] Ensure [PERSONAL_SERVER.md](PERSONAL_SERVER.md) is clearly labeled **maintainer-only** in README or hosting index so self-hosters do not follow the wrong path
- [ ] Confirm [SELF_HOST.md](SELF_HOST.md) is linked from [README.md](../README.md) and [INSTALL.md](INSTALL.md) as the primary self-host entry point

---

## Notes

- **Do not** treat unchecked items as blockers for a soft alpha launch if the Quick launch checklist is complete — Priorities 2–3 can ship incrementally after going public.
- Update this file as items are completed (change `[ ]` to `[x]`).
- For contribution workflow, see [CONTRIBUTING.md](../CONTRIBUTING.md). For security reporting, see [SECURITY.md](../SECURITY.md).
