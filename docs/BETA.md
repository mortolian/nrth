# Beta remaining work

nrth is labelled **beta**. Core invoicing, expenses, VAT, ledger, bank import/reconciliation, teams, and self-host install/upgrade are in place. This page is the checklist for what is **not** done yet, in the same three buckets as the go/no-go discussion.

**Latest GitHub Release tag:** [v0.1.3](https://github.com/mortolian/nrth/releases/tag/v0.1.3) (2026-08-12). Work after that tag (reconciliation, upgrades, security, this beta label) lives on `master` / `master-beta` until the next Release Please tag.

Beta is **not** 1.0. It is not certified accounting, not tax advice, and not a promise that every screen is finished. See [ROADMAP.md](ROADMAP.md) for out-of-scope items.

---

## What “done enough for beta” already means

These were the near-term ROADMAP items. They are closed.

| Area | What that means in practice |
|------|-----------------------------|
| Additive schema + upgrades | From 2026-08-18, new migrations do not drop/rename in `up()`. Operators use `./scripts/backup` then `./scripts/update` ([UPGRADE.md](UPGRADE.md)). |
| Bank reconciliation | Imported lines can be matched (including splits) or excluded on mixed personal/business accounts. |
| Backup & restore | Instance zip via `./scripts/backup`; restore is **CLI only** (an in-app restore would overwrite the live database). |
| Multi-tenant hardening | Public registration is off. Isolation + production `.env` notes: [SELF_HOST.md](SELF_HOST.md#multi-tenant-hardening). |
| Security pass | Private media disk, signed Stripe/PayFast webhooks, invitation/email-change rules. Residuals: [SECURITY.md](../SECURITY.md). |
| Docs + tests | [ARCHITECTURE.md](ARCHITECTURE.md), tenant isolation tests, report arithmetic, invoice overpayment rejection. |

Travel and Planning (budgets) are optional modules and are fine to keep as they are. Wealth CSV / multi-currency portfolios stay **after** this remaining list.

---

## 1. Contracting and provisional tax — still experimental

These two surfaces exist in the app, but they are **not** at the same quality as invoicing, VAT returns, or bank recon. For beta they are labelled experimental rather than rewritten. You can still choose later to harden them or hide them.

They are different features. Do not treat them as one bug.

### 1.1 Contracting (optional module)

**Where it lives**

- Nav: **Contracting** (only if Settings → Features → Contracting is on).
- Code: `app/Domain/Contracting`, `ContractController::generateInvoice`.
- New businesses: module **off** by default.
- Older businesses: a past migration may have opted them **in**. They see Contracting until they turn it off.

**What works**

- Store client contracts (fixed / time-and-materials / retainer).
- Upload a signed PDF (private media disk).
- For retainers, a **Generate Invoice** button creates a draft invoice and opens it.

**What is wrong with Generate Invoice (why it is experimental)**

The button does **not** go through the normal invoicing create/send pipeline (`CreateInvoiceAction` and friends). It inserts an invoice row itself. Consequences:

1. **Currency is always `ZAR`**, even if the business or client invoices in another currency.
2. **VAT is always `0`**, even if the business is VAT-registered. The draft will not pick up the company’s VAT rate.
3. **Numbering is a custom `RET-…` string**, not the business invoice number sequence.
4. **Totals are copied from the retainer amount** (`subtotal = total = monthly amount`). There is no VAT-inclusive/exclusive handling.
5. You still land on the invoice show page, so you can edit the draft by hand. The danger is trusting the generated draft as a finished VAT invoice.

Fixed and time-and-materials contracts do **not** generate invoices this way; only retainer does.

**What “harden” would mean (not done)**

- Create the draft via the same invoicing action as **Invoices → New**.
- Use the team’s currency and VAT settings (and client defaults).
- Use the normal invoice number sequence.
- Optionally schedule retainers instead of a one-shot button.

**What “hide” would mean (not done)**

- Turn Contracting off in Features for the business, or stop opting existing teams in.

Until then: Settings → Features describes Contracting as experimental; the contracts list and retainer generate box repeat that.

### 1.2 Provisional tax (under Tax, not a module)

**Where it lives**

- Nav: **Tax → Tax Periods** (`/tax/provisional`).
- This is **not** an optional module. If the user can open Tax, they can open this tab.
- Code: `ProvisionalTaxController`, `ProvisionalTaxService`.

**What works**

- Shows first/second period cards for the current SA tax year window.
- Shows a **rough** tax estimate and a “safe harbour” figure.
- Lets you type a manual annual income override.
- The page already says estimates are not final.

**What is wrong (why it is experimental)**

1. **Tax tables are a hard-coded rough SA *individual* scale** in `ProvisionalTaxController::roughTax()`. They are not company rates, not kept automatically in sync with SARS, and not a filing engine.
2. **Year-to-date income on the page is interpolated** (annual estimate ÷ 12 × months elapsed), not a sum of posted P&L.
3. **“Recorded payments” are found by SQL `LIKE '%provisional%tax%'`** on transaction description or reference. A payment named something else will not show; a coincidental phrase could. There is no proper tax-payment link.
4. **The “Record Payment” button on the period card does not submit anything** (no click handler). It does not create a journal entry or mark a period paid.
5. Period “paid” vs “upcoming” is mostly “has this tax period been marked submitted,” not “SARS has this amount.”

**What “harden” would mean (not done)**

- Drive suggested amounts from posted books (or an explicit estimate the user owns).
- Record payments as real accounting transactions linked to the period (no `LIKE`).
- Wire or remove the Record Payment button.
- Keep tables and rules in a maintained place, with a stronger “not tax advice” notice (already required).

**What “hide” would mean (not done)**

- Remove or gate the Tax Periods tab until the above exists.

Until then: the provisional page is titled and bannered as experimental. Do not file SARS returns from these numbers.

---

## 2. Publish a beta GitHub Release (process — not more product code)

Relabelling the README **does not** create a tag. Self-hosters who pin with `./scripts/update --ref v0.x.y` still get **v0.1.3** until Release Please runs on `master`.

**Why this is a separate step**

- Tags and [CHANGELOG.md](../CHANGELOG.md) are owned by [Release Please](RELEASE.md). Do not hand-edit the changelog for this.
- This remaining-work tree may still be on **`master-beta`**. GitHub Releases are cut from **`master`**.

**Point by point (maintainer)**

1. **Land this branch on `master`**
   - Open a PR: `master-beta` → `master` (or merge locally and push `master`).
   - Wait for CI (tests workflow) to pass.
   - Merge. Do not skip hooks.
2. **Let Release Please open its PR**
   - Workflow: `.github/workflows/release-please.yml`.
   - It looks at conventional commits since **v0.1.3**.
   - This cycle includes `feat:` work (recon, etc.), so expect a **minor** bump (`0.1.3` → `0.2.0`), not a patch. Confirm the PR title (`chore: release 0.x.y`).
3. **Read the Release Please PR**
   - Check CHANGELOG sections match what you want public.
   - Do not rewrite history; fix by adding a commit on `master` if something is wrong, then wait for the PR to update.
4. **Merge the Release Please PR**
   - That merge creates the git tag (`v0.2.0` or whatever it chose), the GitHub Release, and the changelog commit.
5. **Point operators at the new tag**
   - Example in [INSTALL.md](INSTALL.md) / [UPGRADE.md](UPGRADE.md) still shows `v0.1.3` until you bump those examples in a follow-up `docs:` commit (or include it in the release PR if you edit those files on `master` before merging Release Please).
   - Live instances: `./scripts/backup && ./scripts/update --ref v0.x.y`.
6. **Optional:** on GitHub, set the release notes title to mention **beta** so the Releases page matches the README badge.

There is already a first public release (`v0.1.0`–`v0.1.3`). This item is “first **beta** tag,” not “first tag ever.”

---

## 3. Keep the disclaimer honest (copy — already started in this change)

Beta must not read as “production-certified” or “SARS-ready.” These statements stay true even after item 2.

| Claim | Why it stays |
|-------|----------------|
| **Not financial or tax advice** | VAT returns and reports are bookkeeping aids. Provisional tax is a rough estimate. |
| **Not 1.0 / not a stability guarantee** | Pre-1.0. Prefer tagged releases for live books. `master` can still move. Schema is additive from 2026-08-18; older alpha tags could break. |
| **Balance sheet vs P&L** | The balance sheet does **not** roll net profit into equity, so assets will not equal liabilities + equity the way a closed year would. Report tests assert the current behaviour; it is not a hidden bug. |
| **Email verification is not a login gate** | `MustVerifyEmail` is unused. Accounts get `email_verified_at` at creation. Changing email to a pending invitation or `NRTH_OPERATOR_EMAILS` address stays unverified so it cannot auto-join or become an env operator. |
| **PayFast** | Passphrase + signature + amount match. No extra PayFast server-to-server confirm. |
| **Restore** | Instance restore is CLI / documented steps only. |
| **Optional AI `base_url`** | Can point at an operator-chosen host. |
| **Experimental screens** | Contracting generate-invoice and provisional tax (section 1). |

README, ROADMAP, and SECURITY carry this wording. If you add a public demo later, reuse the same notice (do not imply demo data is a hosted product).

---

## Explicitly not on this list (post-beta / optional)

Do not block a beta tag on these:

- Wealth CSV import and multi-currency portfolios
- Extra bank statement formats / bank-specific CSV presets
- Publishing a Docker image to GHCR
- Hosted docs site
- Composer license scan in CI
- Turning on Fortify email verification as a login requirement
- In-app instance restore

---

## Checklist

Copy this into a GitHub issue if you want it tracked outside the repo.

### 1. Experimental surfaces

- [x] Label Contracting experimental in Settings → Features and on contract screens
- [x] Label provisional tax experimental on the Tax Periods page
- [ ] Decide later: **harden** (section 1.1 / 1.2) or **hide** the surfaces
- [ ] If hardening Contracting: use invoicing pipeline, VAT, currency, numbering
- [ ] If hardening provisional tax: real payment links, working Record Payment, books-based estimates

### 2. Beta release

- [ ] Merge this work to `master`
- [ ] Merge the Release Please PR
- [ ] Confirm the new `v0.x.y` tag on GitHub Releases
- [ ] Update `./scripts/update --ref` examples if they still say `v0.1.3`

### 3. Disclaimer

- [x] README badge and notice say **beta**, with advice / 1.0 caveats
- [x] ROADMAP status is beta
- [ ] After the tag exists, set SECURITY “supported versions” to include that tag if you want tags officially supported (today: `master` is the supported line)

---

## Related docs

| Doc | Role |
|-----|------|
| [ROADMAP.md](ROADMAP.md) | What is in beta vs later vs out of scope |
| [RELEASE.md](RELEASE.md) | How tags are cut |
| [UPGRADE.md](UPGRADE.md) | How self-hosters move between tags |
| [SECURITY.md](../SECURITY.md) | Security residuals and disclaimer |
| [SELF_HOST.md](SELF_HOST.md) | Production `.env`, backups, multi-tenant notes |
