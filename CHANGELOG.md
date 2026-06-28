# Changelog

All notable changes to nrth are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).

## [0.1.0] - 2026-06-28

Initial public alpha. nrth is open-source accounting and finance software for contractors and small businesses, with a South Africa focus (VAT, invoicing, ledger, bank imports). **Not production-ready** — data models and features are still changing.

### Invoicing & estimates

- Clients, contacts, and company settings (logo, issuer details, invoice numbering).
- Invoices with line items, drag-and-drop ordering, multi-currency support (ISO 4217), and VAT-aware totals.
- Estimates (formerly quotes) with PDF generation, send/mark-sent, and conversion to invoices.
- Invoice PDF export (single and bulk ZIP), email sending, payment recording, void/unvoid, and delete (when no payments).
- Online payment sessions with Stripe and PayFast webhooks.
- Dashboard KPIs, overdue tracking, and invoice status filters.

### Expenses & suppliers

- Expense entry with categories (chart of accounts), VAT treatment, receipt uploads, and supplier linking.
- Supplier management (CRUD, expense history).
- Travel and home-office expense helpers (km rate, office percentage).

### Accounting

- Chart of accounts with parent/child structure and system account protection.
- Journal entries, general ledger, account statements, and transaction posting/voiding.
- Team-scoped ledger using `brick/money` (amounts stored in cents).

### Banking

- Bank accounts and transaction list.
- Statement import pipeline for CSV and OFX with column mapping, preview, duplicate detection, and confirm step.

### Tax

- VAT rates and company VAT registration settings.
- VAT returns and tax periods with Excel export (PhpSpreadsheet).
- Provisional tax service scaffold.

### Budgeting

- Budgets by category with monthly variance, expandable rows, and soft delete/restore.

### Teams & onboarding

- Laravel Jetstream with Inertia, Vue 3, and multi-user teams.
- Setup wizard for company profile, VAT, chart of accounts, and invoice defaults.

### Self-hosting

- Docker Compose stack: Octane (Swoole), Horizon worker, scheduler, PostgreSQL, Redis, MinIO, optional Caddy TLS proxy.
- One-command install script (`scripts/install.sh`) for Ubuntu 22.04/24.04 with production, dev, LAN, repair, and non-interactive modes.
- `app:install` and `app:update` Artisan commands; `./scripts/reset.sh` for full reset.
- HTTPS enforcement in production; pragmatic HTTP/LAN access for local dev via `APP_ALLOW_HTTP`.
- Data-risk acknowledgment for non-interactive installs and resets.

### Open source & community

- MIT license, contributing guide, code of conduct, and security policy.
- GitHub issue/PR templates, CI test workflow (PHP 8.3–8.5), and documentation hub (`docs/INSTALL.md`, `docs/SELF_HOST.md`, `docs/DEVELOPMENT.md`).

### Known limitations (alpha)

- No semver stability guarantee — expect breaking migrations and API changes on `master`.
- Not audited accounting or tax advice — evaluate carefully before relying on outputs.
- Some domains (e.g. contracting, provisional tax) are early or incomplete.
- Self-hosters must harden their own deployments (HTTPS, secrets, backups) — see [SECURITY.md](SECURITY.md) and [docs/SELF_HOST.md](docs/SELF_HOST.md).

[0.1.0]: https://github.com/mortolian/nrth/releases/tag/v0.1.0
