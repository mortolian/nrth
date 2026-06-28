# Roadmap

NRTH is in **alpha**. This document describes what works today, what we plan next, and what is intentionally out of scope for now. Priorities may shift based on feedback — open a [Discussion](https://github.com/mortolian/nrth/discussions) or [issue](https://github.com/mortolian/nrth/issues) to suggest changes.

**Status:** Alpha · **Current release:** [v0.1.0](https://github.com/mortolian/nrth/releases/tag/v0.1.0)

---

## In alpha today

These areas exist and are usable, but expect rough edges and breaking changes:

| Area | Notes |
|------|-------|
| **Invoicing & estimates** | Clients, PDFs, email, payments, Stripe/PayFast pay links, multi-currency |
| **Expenses & suppliers** | Receipts, VAT on purchases, categories, supplier records |
| **Accounting** | Chart of accounts, journal, general ledger, account statements |
| **Banking** | CSV/OFX import, duplicate detection, transaction list |
| **Tax (VAT)** | Rates, returns, periods — SA-oriented defaults |
| **Budgeting** | Category budgets with variance views |
| **Teams** | Multi-user companies via Jetstream |
| **Self-hosting** | Docker Compose install via `scripts/install.sh` |

See [README.md](../README.md) and [CHANGELOG.md](../CHANGELOG.md) for details.

---

## Near term (toward beta)

Planned focus areas — not committed dates or ordering:

- [ ] **Stabilise data models** — fewer breaking migrations; clearer upgrade path between tags
- [ ] **Bank reconciliation** — match imported transactions to invoices, expenses, and journal entries
- [ ] **Backup & restore docs** — prominent Spatie Backup guidance for self-hosters
- [ ] **Multi-tenant hardening guide** — registration, team isolation, production `.env` warnings
- [ ] **README screenshots / demo** — visual overview for new visitors
- [ ] **Architecture docs** — domain layout, actions pattern, team scoping ([`app/Domain/`](../app/Domain/))
- [ ] **Security review** — auth flows, uploads, payment webhooks
- [ ] **Expanded test coverage** — critical money and tenant-isolation paths

Track progress via [GitHub issues](https://github.com/mortolian/nrth/issues) and [Discussions](https://github.com/mortolian/nrth/discussions).

---

## Later / exploratory

Ideas under consideration, not scheduled:

- Additional bank statement formats and bank-specific CSV presets
- Recurring invoices and expense rules
- Deeper SARS reporting (beyond current VAT scaffolding)
- Mobile-friendly UI improvements
- Published Docker image (GHCR) for simpler pulls
- Hosted documentation site

---

## Explicitly out of scope (for now)

To set expectations during alpha:

| Topic | Why |
|-------|-----|
| **Certified / audited accounting** | Open-source tool, not a regulated audit product |
| **Professional tax advice** | Software assists record-keeping; consult a practitioner for compliance |
| **Multi-country tax engines** | SA-first; international currency support ≠ international tax rules |
| **Managed SaaS hosting by maintainers** | Self-hosted only; no official nrth.cloud at this stage |
| **Mobile native apps** | Web UI only |
| **Full ERP** | Inventory, payroll, CRM, etc. are not goals for alpha/beta |

---

## Versioning

- **`master`** — active development; may break without notice during alpha.
- **Tags (`v0.x.y`)** — created automatically when you merge a Release Please PR; see [docs/RELEASE.md](RELEASE.md).
- **1.0** — not planned until data models, install/upgrade story, and core workflows are stable enough for production-minded self-hosters.

---

## How to influence the roadmap

1. **Bug** → [bug report issue](https://github.com/mortolian/nrth/issues/new?template=bug_report.yml)
2. **Feature** → [feature request issue](https://github.com/mortolian/nrth/issues/new?template=feature_request.yml)
3. **Question or idea** → [Discussions](https://github.com/mortolian/nrth/discussions)
4. **Code** → [CONTRIBUTING.md](../CONTRIBUTING.md)

We prioritise issues that describe a real workflow problem, include reproduction steps or mockups, and align with the self-hosted SME/contractor focus.
