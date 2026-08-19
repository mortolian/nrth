# Security Policy

## Supported versions

nrth is pre-1.0 and under active development. Security fixes are applied on the latest `master` branch. Tagged releases will be listed here as the project stabilises.

| Version | Supported          |
| ------- | ------------------ |
| master  | :white_check_mark: |
| < 0.1.0 | :x:                |

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you discover a security issue, report it privately so we can address it before public disclosure.

**Preferred:** [GitHub Security Advisories](https://github.com/mortolian/nrth/security/advisories/new) (private report)

**Email (optional):** security@mortolian.com — use if you cannot access GitHub advisories

Include as much detail as possible:

- Description of the issue and potential impact
- Steps to reproduce
- Affected version or commit
- Any proof-of-concept or suggested fix (optional)

We aim to acknowledge reports within **72 hours** and will keep you informed of progress.

## What to report

Examples of issues we care about:

- Authentication or session bypass
- Cross-team data access (tenant isolation)
- SQL injection, XSS, CSRF in sensitive flows
- Insecure file upload or download
- Webhook or payment integration flaws (Stripe, PayFast)
- Secrets or credentials exposed in the repository

## What is out of scope

- Denial-of-service against a self-hosted instance without a demonstrated application flaw
- Issues in third-party services (report to the vendor)
- Missing security headers on deployments you control
- Social engineering

## Safe harbour

We appreciate responsible disclosure. We will not pursue legal action against researchers who report issues in good faith and follow this policy.

## Self-hosters

If you run your own instance:

- Keep `APP_DEBUG=false` in production
- Use HTTPS and strong passwords for database, Redis, and MinIO
- Apply updates from `master` or tagged releases promptly
- See [docs/SELF_HOST.md](docs/SELF_HOST.md) (HTTPS, backups, [multi-tenant hardening](docs/SELF_HOST.md#multi-tenant-hardening))

### Beta review (2026-08-18)

Reviewed auth, uploads, public pay, and Stripe/PayFast webhooks. Changes shipped with that review:

- PayFast **passphrase is required** when the gateway is enabled; ITN is rejected if it is missing (unsigned MD5 is not accepted)
- Stripe **webhook signing secret is required** when Stripe is enabled
- Business logos reject SVG; expense receipts are limited to JPEG/PNG/GIF/WebP/PDF
- Invoice PDFs and receipts are stored on the **private** media disk (not `/storage/…` after `storage:link`). Logos stay on the public disk. `./scripts/update` runs `nrth:move-media-to-private-disk`
- DomPDF does not fetch remote URLs from invoice markdown
- Online payment completion loads invoices without TeamScope (webhooks are unauthenticated HTTP). Starting a new Stripe/PayFast checkout cancels earlier pending sessions in the database; Stripe Checkout Sessions are expired at the gateway when possible. Completing payment still records a **Cancelled** session if that checkout is paid and the invoice is still due (retry then pay the first link). A second successful gateway payment is rejected by the overpayment check.
- PayFast sandbox defaults to **off** (`PAYFAST_SANDBOX=false`); the public “payment completed” banner only shows after a completed checkout session
- Changing your email requires the current password. A safe new address is marked verified again. If the new address matches a pending invitation or `NRTH_OPERATOR_EMAILS`, `email_verified_at` stays empty so it cannot auto-join or grant operator access. Accounts created via install or `User::create()` persist `email_verified_at` even though `MustVerifyEmail` is unused.
- Session idle timeout applies to the full `web` stack (including Jetstream profile and team pages)
- Invitation join links expire after 7 days
- `is_instance_operator` is not mass-assignable

Residual (accepted for self-host / later hardening):

- Email verification is off (`MustVerifyEmail` unused as a login gate). New accounts still get `email_verified_at` at creation. Invitees must already have an account; public registration is disabled
- PayFast does not call PayFast’s extra server-to-server confirm step; passphrase + signature + amount match is the bar
- Optional AI `base_url` can point at an operator-chosen host (`settings.business`)
- Multi-tenant operator guide: [docs/SELF_HOST.md](docs/SELF_HOST.md#multi-tenant-hardening) (production `.env` warnings in `.env.example`)

## Disclaimer

nrth is accounting software provided as-is, currently in **beta**. It is not a substitute for professional financial or tax advice. Use at your own risk. See [docs/BETA.md](docs/BETA.md) for remaining release steps.
