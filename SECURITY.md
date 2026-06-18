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
- See [docs/SELF_HOST.md](docs/SELF_HOST.md)

## Disclaimer

nrth is accounting software provided as-is. It is not a substitute for professional financial or tax advice. Use at your own risk.
