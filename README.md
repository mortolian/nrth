<p align="center">
  <img src="public/images/nrth-logo.svg" alt="nrth" width="80" />
</p>

<h1 align="center">nrth</h1>

<p align="center">
  Open-source accounting &amp; finance for contractors and small businesses.<br />
  Built for South Africa — invoicing, expenses, VAT, ledger, and bank statement imports.
</p>

<p align="center">
  <a href="https://github.com/mortolian/nrth/actions/workflows/tests.yml"><img src="https://github.com/mortolian/nrth/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/mortolian/nrth" alt="License"></a>
  <img src="https://img.shields.io/badge/status-alpha-orange" alt="Alpha">
</p>

> **Early development (alpha).** Features and data models are still changing. Not financial or tax advice — evaluate carefully before production use. See [SECURITY.md](SECURITY.md).

---

## Features

- **Invoicing & estimates** — clients, PDFs, payments, online pay links
- **Expenses & suppliers** — receipts, categories, VAT on purchases
- **Accounting** — chart of accounts, journal, general ledger, account statements
- **Banking** — import CSV/OFX statements, duplicate detection, transaction list
- **Tax** — VAT returns and rates (South African defaults on install)
- **Teams** — multi-user companies via Jetstream
- **Self-hosted** — Docker Compose stack with Postgres, Redis, and MinIO included

## Quick start (Docker)

The fastest way to try nrth with the full stack:

```bash
git clone https://github.com/mortolian/nrth.git
cd nrth
cp .env.example .env
chmod +x scripts/self-host-install.sh
./scripts/self-host-install.sh
```

Open **http://localhost:8000** and complete the installer (`app:install`).

Full production checklist and HTTPS: **[docs/SELF_HOST.md](docs/SELF_HOST.md)** · **[INSTALL.md](INSTALL.md)**

## Development setup

For hacking on the codebase (PHP + Node on the host):

```bash
cp .env.example .env
composer install && php artisan key:generate && php artisan migrate
npm install && npm run dev
php artisan serve   # second terminal
```

Details, Docker dev workflow, architecture, and tests: **[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)**

## Documentation

| Guide | Description |
|-------|-------------|
| [INSTALL.md](INSTALL.md) | Installation hub |
| [docs/SELF_HOST.md](docs/SELF_HOST.md) | Self-host with Docker |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Local development |
| [docs/PERSONAL_SERVER.md](docs/PERSONAL_SERVER.md) | Maintainer auto-deploy server |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

## Contributing

Contributions are welcome — issues, docs, and pull requests.

- [Contributing guide](CONTRIBUTING.md)
- [Code of conduct](CODE_OF_CONDUCT.md)
- [Security policy](SECURITY.md) — report vulnerabilities privately

Please search [existing issues](https://github.com/mortolian/nrth/issues) before opening a new one.

## Tech stack

Laravel 13 · Jetstream (Inertia + Teams) · Vue 3 · Tailwind CSS v4 · PostgreSQL · Redis · Octane (Swoole) · Horizon

Domain logic lives under `app/Domain/`. See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for more.

## License

[MIT](LICENSE) — Copyright (c) nrth contributors.
