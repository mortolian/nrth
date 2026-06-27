# Contributing to nrth

Thank you for your interest in contributing. This project is in **early development** — clear issues, small pull requests, and good tests are especially welcome.

## Before you start

- Read [README.md](README.md) for what the project does and its current status.
- Check [open issues](https://github.com/mortolian/nrth/issues) and [discussions](https://github.com/mortolian/nrth/discussions) to avoid duplicate work.
- For security issues, do **not** open a public issue — see [SECURITY.md](SECURITY.md).

## Ways to contribute

- Report bugs with reproducible steps
- Suggest features (explain the problem, not only the solution)
- Improve documentation
- Fix issues or add tests via pull request

## Development setup

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for local setup (PHP + Node, or Docker Compose).

Quick path:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm install
npm run dev
# separate terminal:
php artisan serve
```

Or use the unified installer from a clone: `./scripts/install.sh --dev`

## Pull request process

1. **Fork** the repository and create a branch from `master`:
   - `fix/short-description` for bug fixes
   - `feat/short-description` for features
   - `docs/short-description` for documentation only

2. **Make your changes** with focused commits.

3. **Run checks** before opening the PR:

   ```bash
   php artisan test
   ./vendor/bin/pint
   ```

   If you changed frontend code, run `npm run build` to ensure assets compile.

4. **Open a pull request** against `master` and fill in the PR template.

5. **Respond to review feedback** — maintainers may ask for changes or tests.

## Code guidelines

- Follow existing patterns under `app/Domain/` (actions, DTOs, services, team scoping).
- Keep controllers thin; put business logic in domain actions/services.
- Use `brick/money` / cents for ledger amounts; do not mix with import-only decimal fields unless intentional.
- Run [Laravel Pint](https://laravel.com/docs/pint) on changed PHP files: `./vendor/bin/pint`
- Add or update tests for behavior changes when practical.
- Avoid unrelated refactors in the same PR.

## Database migrations

- Name migrations clearly and keep them reversible when possible.
- Do not edit migrations that may already be applied in the wild — add a new migration instead.

## Documentation

- Update README, `docs/`, or [docs/INSTALL.md](docs/INSTALL.md) when you change setup, hosting, or user-visible behavior.
- Link new docs from [docs/INSTALL.md](docs/INSTALL.md) or README if they are entry points.

## Community

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md). Be respectful and constructive.

## Questions

Open a [GitHub Discussion](https://github.com/mortolian/nrth/discussions) for questions. Use [issues](https://github.com/mortolian/nrth/issues) for confirmed bugs and feature requests.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
