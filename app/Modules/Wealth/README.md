# Wealth module

Optional NRTH module (disabled by default). Toggle under **Settings → Features**.

## Domain

- **Portfolio** — groups assets; configurable financial-year start month; single base currency (v1). Teams can create multiple portfolios and switch between them (selection remembered in session).
- **Asset** — investment/savings/retirement/etc. with liquidity classification
- **Valuation** — dated balance snapshots (one per asset per day); current value = latest
- **Transaction** — contribution, withdrawal, interest, dividend, fee, adjustment
- **Contribution allowance** — optional annual limits (TFSA-shaped); remaining derived from contributions

Investment movement for a period:

`closing − opening − contributions + withdrawals`

(opening = value on the day before the period start)

## Layout

- PHP: `app/Modules/Wealth/`
- Inertia pages: `resources/js/Pages/Wealth/`
- Provider: `WealthServiceProvider` (routes, migrations, `WealthAssetValueProvider` binding)

## Future

CSV import extension point; broker APIs out of scope. Net Worth can consume `WealthAssetValueProvider` without coupling to Wealth tables.
