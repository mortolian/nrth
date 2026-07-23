# Changelog

All notable changes to nrth are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).

## [0.1.1](https://github.com/mortolian/nrth/compare/v0.1.0...v0.1.1) (2026-07-23)


### Features

* add attachment management to expense transactions ([febcd4a](https://github.com/mortolian/nrth/commit/febcd4a55d0120dab363ac8d1554df4cf86446d7))
* add confirmation prompts for receipt and attachment removal in expense form ([56708fd](https://github.com/mortolian/nrth/commit/56708fddfa40e6a28df2e5131e9b09c69ca4a69c))
* add CSV export functionality for selected expenses ([557a8e6](https://github.com/mortolian/nrth/commit/557a8e669a91b5b1c20e2efdb34f5714fdf7d56b))
* enhance account creation and editing with suggested codes ([ecbeac6](https://github.com/mortolian/nrth/commit/ecbeac627b0debbbe21fc5e5a531b9194be7d810))
* enhance AppCard component with forwarded attributes ([3062b38](https://github.com/mortolian/nrth/commit/3062b38e97b138c0176f280210790709df503d75))
* enhance command palette with dynamic navigation and quick actions ([6d5483b](https://github.com/mortolian/nrth/commit/6d5483b7a8938aed7fd0f5afd6ac8f6643a40eef))
* enhance expense and supplier management with team-specific chart accounts ([beafb8f](https://github.com/mortolian/nrth/commit/beafb8f2ad13bbb0debf4093088d12e3129f0be6))
* enhance expense management with VAT calculations and total amounts ([66fe2c5](https://github.com/mortolian/nrth/commit/66fe2c54cf07f83b3617f896d90a395f6170376e))
* enhance expense row interaction with accessibility improvements ([98b6360](https://github.com/mortolian/nrth/commit/98b6360607fbcf5e4fd000539700fbdfe8d587aa))
* enhance receipt preview functionality in expense form ([7ade5d1](https://github.com/mortolian/nrth/commit/7ade5d166de0b421272bb85284b2f4007c6301db))
* implement attachment deletion and management in expense transactions ([1039eed](https://github.com/mortolian/nrth/commit/1039eed34612628ee0ac87871dbc2f6904c0a835))
* implement attachment removal functionality in expense management ([54ebcc5](https://github.com/mortolian/nrth/commit/54ebcc50ca4b3321f25b4985414bfd78543044a8))
* implement session idle timeout feature in settings ([5f8bd4a](https://github.com/mortolian/nrth/commit/5f8bd4a92e0a816e666b075d65c95e4ff149565a))
* improve banking account management and receipt handling ([1cdab20](https://github.com/mortolian/nrth/commit/1cdab20a8c838c897476942f0ac42d1346eac42e))
* integrate AI features for expense management ([f7e9984](https://github.com/mortolian/nrth/commit/f7e9984ce66dbae7f2f7f7e442e19b6e84a0a559))
* integrate banking account selection in invoicing and expense management ([8b5eb7a](https://github.com/mortolian/nrth/commit/8b5eb7aa9149a93fbda8c5b968d9b08e87d6502e))
* refactor expense management to use paid from account selection ([3b180ca](https://github.com/mortolian/nrth/commit/3b180ca030d765dd1c2aaaae98597ec08210cd9d))
* support multiple file uploads for receipt parsing ([c64a618](https://github.com/mortolian/nrth/commit/c64a618335ec54218f772cf6962d1c0a7ed8ff94))
* support multiple receipt uploads in expense management ([e6b980c](https://github.com/mortolian/nrth/commit/e6b980c77b0cb3334c9a261700c6badc9dca3876))
* update expense form with new supplier button and icon ([45f6e1f](https://github.com/mortolian/nrth/commit/45f6e1ffa435d2dc2d0c2717a8d588be1f4cbcae))


### Documentation

* clarify that --lan HTTP is trusted-network only ([2638fdc](https://github.com/mortolian/nrth/commit/2638fdc390818f48b996b81fdd6f7a0ac063a1d9))
* clarify that --lan HTTP is trusted-network only ([#5](https://github.com/mortolian/nrth/issues/5)) ([547183e](https://github.com/mortolian/nrth/commit/547183e41ecb2cd2bced6b14d6e788a5817b0116))
* document Release Please Actions PR permission setting ([0f587eb](https://github.com/mortolian/nrth/commit/0f587eb02de04f5a3b5014fd54beb4bdc6cb6137))
* document Release Please Actions PR permission setting ([#7](https://github.com/mortolian/nrth/issues/7)) ([3ed8430](https://github.com/mortolian/nrth/commit/3ed843059eb63be48008f3f1fa44f5ccf6297163))

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
