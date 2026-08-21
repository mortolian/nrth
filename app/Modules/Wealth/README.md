# Wealth module

Optional NRTH module (disabled by default). Toggle under **Settings → Features**.

## Domain

- **Portfolio** — groups assets; configurable financial-year start month; single base currency (v1). Teams can create multiple portfolios and switch between them (selection remembered in session).
- **Asset** — investment/savings/retirement/etc. with liquidity classification
- **Valuation** — dated balance snapshots (one per asset per day); current value = latest
- **Transaction** — contribution, withdrawal, interest, dividend, fee, adjustment
- **Contribution allowance** — optional annual limits (TFSA-shaped); remaining derived from contributions

Investment movement for a period (month, financial year, trailing year, or yearly summary row):

`closing − opening − contributions + withdrawals`

**Opening (financial year):** last valuation dated in the *previous* financial year
(on or before the day before this FY starts). If none exists, opening is the first
valuation *inside* this FY (synthetic open), and only contributions/withdrawals on
or after that date are counted — so missing years do not dump multi-year gains into
the current FY.

**Opening (trailing year / YoY):** last valuation on or before the day before
(as-of − 1 year), with normal carry-forward. Synthetic open if the portfolio had no
prior valuation (first valuation inside the window).

**Closing:** last valuation on or before the period end (as-of today for the current FY).

**Contributions / withdrawals:** contribution, withdrawal, and adjustment flows in the
period (interest, dividend, and fee are history-only and already reflected in valuations).

Portfolio overview includes a **year-end portfolio value** table: market value at each financial-year
end (and YTD for the current year), with movement vs the prior year-end balance.

**Archive / delete:** Soft-delete archives assets and portfolios. Archived rows are hidden
from the switcher and assets table unless **Show archived** is on (they appear with an
Archived badge; KPIs still exclude them). Restore undoes archive. Permanent delete removes
the record (assets cascade valuations/transactions; deleting a portfolio permanently
removes its assets). Former `is_active = false` assets are migrated to archived.

## Layout

- PHP: `app/Modules/Wealth/`
- Inertia pages: `resources/js/Pages/Wealth/`
- Provider: `WealthServiceProvider` (routes, migrations, `WealthAssetValueProvider` binding)

## Future

CSV import extension point; broker APIs out of scope. Net Worth can consume `WealthAssetValueProvider` without coupling to Wealth tables.
