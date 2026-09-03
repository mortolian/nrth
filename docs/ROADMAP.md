# Roadmap

NRTH is in **beta**. This document describes what works today, what we plan next, and what is intentionally out of scope. Priorities may shift based on feedback — open a [Discussion](https://github.com/mortolian/nrth/discussions) or [issue](https://github.com/mortolian/nrth/issues) to suggest changes.

**Status:** Beta · **Latest tag:** [v0.1.3](https://github.com/mortolian/nrth/releases/tag/v0.1.3)

---

## In beta today

These areas exist and are usable. 1.0 is not promised yet.

| Area | Notes |
|------|-------|
| **Invoicing & estimates** | Clients, items catalog, recurring invoices, discounts, note templates, income accounts on send, PDFs, email, payments, Stripe/PayFast pay links, multi-currency |
| **Expenses & suppliers** | Receipts, VAT on purchases, categories, supplier records |
| **Accounting** | Chart of accounts, journal, general ledger, account statements |
| **Banking** | CSV/OFX import, duplicate detection, match/exclude imported transactions |
| **Tax (VAT)** | Rates, returns, periods — SA-oriented defaults |
| **Budgeting** | Category budgets with variance views |
| **Teams** | Multi-user businesses via Jetstream |
| **Optional modules** | Settings → Features; Travel, Planning, and Wealth off by default for new businesses. |
| **Self-hosting** | Docker Compose install via `scripts/install.sh` |

See [README.md](../README.md) and [CHANGELOG.md](../CHANGELOG.md) for details.

---

## Near term (closed for this beta)

- [x] **Stabilise data models** — additive schema from 2026-08-18; tag-to-tag upgrades via `./scripts/update --ref` ([docs/UPGRADE.md](UPGRADE.md))
- [x] **Bank reconciliation** — match imported transactions to invoices, expenses, and journal entries; exclude personal/out-of-scope lines on mixed accounts
- [x] **Backup & restore docs** — instance backup (`./scripts/backup`), offsite, retention, and CLI restore ([docs/SELF_HOST.md](SELF_HOST.md))
- [x] **Multi-tenant hardening guide** — registration, team isolation, production `.env` warnings ([docs/SELF_HOST.md](SELF_HOST.md#multi-tenant-hardening))
- [x] **README screenshots / demo** — visual overview for new visitors
- [x] **Architecture docs** — domain layout, actions, team scoping ([docs/ARCHITECTURE.md](ARCHITECTURE.md))
- [x] **Security review** — auth, uploads, public pay, Stripe/PayFast webhooks, invitation/email-change hardening ([SECURITY.md](../SECURITY.md))
- [x] **Expanded test coverage** — tenant isolation plus report arithmetic (P&L / TB / BS / cash flow) and invoice overpayment rejection

Track progress via [GitHub issues](https://github.com/mortolian/nrth/issues) and [Discussions](https://github.com/mortolian/nrth/discussions). Tags are cut from `master` via [RELEASE.md](RELEASE.md).

---

## Later / exploratory

Ideas under consideration, not scheduled:

- Wealth module refinements (CSV import, multi-currency portfolios)
- Additional bank statement formats and bank-specific CSV presets
- Expense rules (recurring expenses)
- Deeper SARS reporting (beyond current VAT scaffolding)
- Mobile-friendly UI improvements
- Published Docker image (GHCR) for simpler pulls
- Hosted documentation site
- Third-party plugin API (only after several internal modules share a stable contract)

---

## Explicitly out of scope (for now)

To set expectations during beta:

| Topic | Why |
|-------|-----|
| **Certified / audited accounting** | Open-source tool, not a regulated audit product |
| **Professional tax advice** | Software assists record-keeping; consult a practitioner for compliance |
| **Multi-country tax engines** | SA-first; international currency support ≠ international tax rules |
| **Managed SaaS hosting by maintainers** | Self-hosted only; no official nrth.cloud at this stage |
| **Mobile native apps** | Web UI only |
| **Full ERP** | Inventory, payroll, CRM, etc. are not goals for beta |

---

## Versioning

- **`master`** — active development; still the default `./scripts/update` target.
- **Tags (`v0.x.y`)** — created automatically when you merge a Release Please PR; see [docs/RELEASE.md](RELEASE.md). Self-hosters who want a known release should use `./scripts/update --ref v0.x.y` ([docs/UPGRADE.md](UPGRADE.md)).
- **Schema** — from 2026-08-18, new migrations are additive (no drop/rename in `up()`). Tags before that policy may have included breaking migrations.
- **1.0** — not planned until data models, install/upgrade story, and core workflows are stable enough for production-minded self-hosters.

---

## How to influence the roadmap

1. **Bug** → [bug report issue](https://github.com/mortolian/nrth/issues/new?template=bug_report.yml)
2. **Feature** → [feature request issue](https://github.com/mortolian/nrth/issues/new?template=feature_request.yml)
3. **Question or idea** → [Discussions](https://github.com/mortolian/nrth/discussions)
4. **Code** → [CONTRIBUTING.md](../CONTRIBUTING.md)

We prioritise issues that describe a real workflow problem, include reproduction steps or mockups, and align with the self-hosted SME/contractor focus.
