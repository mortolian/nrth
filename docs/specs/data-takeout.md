# Data takeout — implementation spec

**Status:** Implemented (steps 1–5; core tests in step 6)  
**Target:** Tax → Documents  
**Goal:** One owner-only action for a date range that produces a self-contained zip of figures and supporting documents for tax preparation and offline archive.

---

## Problem

Self-hosters need a period snapshot they can:

- Copy into an existing personal folder structure (e.g. `TAX - Tax Season ending Feb 2027/`)
- Back up as files only, without relying on nrth or Postgres to interpret documents
- Hand to SARS or an accountant with human-readable filenames and summary figures

nrth’s live storage uses opaque paths (`media` IDs, hashed bank imports). Day-to-day use should not change; takeout is an **on-demand export**, not a mirror.

---

## Non-goals

- Ongoing filesystem sync or tax-season folder naming inside nrth
- Full database or application backup (Spatie Backup remains separate)
- Estimate / quote exports
- Replacing nrth as the system of record
- Access for non-owner team roles in v1

---

## User workflow

```
During the year     → Use nrth normally (no extra filing)

Before tax season   → Tax → Documents
                    → Select date range (or preset)
                    → Review pre-flight summary
                    → Generate takeout

When notified       → Download zip (link expires)
                    → Unzip / copy into personal TAX folder
                    → Back up that folder as today
```

**Design principle:** Simplify tax prep; do not add ongoing tasks.

---

## Locked product decisions

| Topic | Decision |
|-------|----------|
| Delivery | Background job → notification → time-limited download link |
| Freshness | Always generated from current data; no server-side archive of past takeouts beyond short-lived download files |
| Voided invoices | Included in registers with status; PDF included if media exists |
| Currency | Registers include original currency + company (ZAR) columns where the app stores them |
| Permissions | Team owner only (`$user->ownsTeam($team)`) |
| Estimates | Excluded |
| Figure formats | CSV **and** `.xlsx` for each register (same data) |
| Bank statements | Include import file if **any** parsed transaction falls in the selected date range |
| Contracts | In v1 (see inclusion rules) |
| VAT periods | All periods overlapping the range; include `status` column |

---

## UI — Tax → Documents

Replace the placeholder “Generate Tax Pack” progress UI with:

### Date selection

- **From** / **To** date inputs (inclusive)
- **Presets** (convenience only; not tax-season logic in storage):
  - Previous SA tax year — 1 March → last day of February (reuse `ReportsController::taxYearWindow` logic)
  - This SA tax year
  - Custom

### Pre-flight summary (before queueing)

Show counts and warnings derived from the same queries the job will use:

- Invoices (including voided); draft excluded
- Expenses; receipts present / missing
- Bank statement files to include; months with no imports touching the range
- Contracts with / without signed file
- VAT periods overlapping range

### Actions

- **Generate takeout** — owner only; queues job
- **Recent takeouts** — table: period, requested at, status, download (if ready and not expired), error message (if failed)

### While processing

- Status: `queued` → `processing` → `ready` | `failed`
- Notify team owner when `ready` or `failed`

---

## Delivery architecture

```
POST /tax/takeouts          → create TakeoutRun, dispatch job
GET  /tax/takeouts          → list recent runs (Inertia props or JSON)
GET  /tax/takeouts/{token}/download → stream zip (owner + valid token + not expired)
```

### `takeout_runs` table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `team_id` | FK teams | |
| `requested_by` | FK users | Must be team owner at request time |
| `from_date` | date | Inclusive |
| `to_date` | date | Inclusive |
| `status` | string | `queued`, `processing`, `ready`, `failed`, `expired` |
| `download_token` | string | Unique, unguessable; used in download URL |
| `storage_path` | string nullable | Relative to `local` disk, e.g. `takeouts/{uuid}.zip` |
| `file_size_bytes` | bigint nullable | |
| `manifest` | json nullable | Counts, totals, warnings (for UI + `manifest.json` in zip) |
| `error_message` | text nullable | |
| `expires_at` | timestamp | Default: `completed_at + 7 days` |
| `completed_at` | timestamp nullable | |
| `timestamps` | | |

Indexes: `(team_id, created_at)`, unique `download_token`.

### Job: `GenerateTakeoutJob`

1. Mark run `processing`
2. Build zip in temp path under `storage/app/private/takeouts/`
3. Write `manifest.json`, `README.txt`, `gaps.txt`, `figures/*`, `documents/*`
4. Move/rename to final path; set `storage_path`, `file_size_bytes`, `manifest`, `completed_at`, `expires_at`, `status=ready`
5. On failure: `status=failed`, `error_message`, notify owner
6. Notify owner via Laravel database notification

Zip build must be transactional: delete partial files on failure.

### Notifications

- **Channel:** `database` (requires `notifications` migration if not present)
- **Recipient:** requesting user (team owner)
- **Types:** `TakeoutReady`, `TakeoutFailed`
- Email: out of scope for v1 (optional later via user preferences)

### Download security

- `abort_unless($user->ownsTeam($takeoutRun->team))`
- Token must match and `status === ready`
- `expires_at` must be in the future
- Stream file with `Content-Disposition: attachment`

### Cleanup

- Scheduled command `takeouts:prune` (daily): delete zip files and mark runs `expired` where `expires_at < now()`
- Register in `routes/console.php`

---

## Zip layout

Root folder name:

```
nrth-takeout_{from_date}_to_{to_date}/
```

Example: `nrth-takeout_2026-03-01_to_2027-02-28/`

```
nrth-takeout_2026-03-01_to_2027-02-28/
├── README.txt              # Human summary (see below)
├── manifest.json           # Machine-readable index + counts
├── gaps.txt                # Plain-text missing items
│
├── figures/
│   ├── income-statement.csv
│   ├── income-statement.xlsx
│   ├── trial-balance.csv
│   ├── trial-balance.xlsx
│   ├── invoices-register.csv
│   ├── invoices-register.xlsx
│   ├── expenses-register.csv
│   ├── expenses-register.xlsx
│   ├── payments-register.csv
│   ├── payments-register.xlsx
│   ├── bank-transactions.csv
│   ├── bank-transactions.xlsx
│   ├── vat-periods.csv
│   ├── vat-periods.xlsx
│   ├── contracts-register.csv
│   └── contracts-register.xlsx
│
└── documents/
    ├── invoices/
    ├── expense-receipts/
    ├── bank-statements/
    └── contracts/
```

---

## README.txt (required content)

- Team / company name
- Export period (`from_date` – `to_date`)
- Generated at (UTC + team timezone if configured)
- Row counts per category
- Date field conventions used (document in this spec)
- Note that P&L excludes voided invoices per app rules (if true)
- Pointer to `gaps.txt` for missing supporting documents

---

## manifest.json

```json
{
  "version": 1,
  "team_id": 1,
  "team_name": "Acme Pty Ltd",
  "from_date": "2026-03-01",
  "to_date": "2027-02-28",
  "generated_at": "2027-03-15T10:00:00Z",
  "counts": {
    "invoices": 47,
    "expense_receipts": 118,
    "bank_statement_files": 8,
    "contracts": 3
  },
  "warnings": [
    "14 expenses without receipts",
    "1 contract without signed file"
  ],
  "files": [
    { "path": "figures/invoices-register.csv", "sha256": "…" }
  ]
}
```

`files` with checksums: recommended for v1 if cheap; optional if time-constrained.

---

## Inclusion rules

All queries are team-scoped. Date ranges are **inclusive** on calendar dates.

### Invoices

| Artifact | Rule |
|----------|------|
| Register row | `issue_date` between `from_date` and `to_date`; **include voided**; **exclude draft** |
| PDF | Same set; use existing media if present, else generate via `InvoicePdfService::renderToTemporaryPath` (same as bulk zip) |

**Register columns (minimum):**

`id`, `number`, `status`, `issue_date`, `due_date`, `client_name`, `currency`, `subtotal_cents`, `vat_amount_cents`, `total_cents`, `company_currency_code`, `fx_rate_invoice_to_company`, `fx_rate_date`, `total_company_currency_cents`, `voided_at`, `sent_at`, `pdf_filename`

When invoice is ZAR-only, company columns mirror invoice amounts (`company_currency_code` = team default).

**PDF filename pattern:**

```
{issue_date}_{number}_{sanitized_client_name}.pdf
```

Example: `2026-04-12_INV-2026-0018_Acme-Corp.pdf`

### Expenses

| Artifact | Rule |
|----------|------|
| Register row | `type = expense`, `status = posted`, `transaction_date` in range |
| Receipt file | Media in `attachments` collection for included expense |

**Register columns (minimum):**

`id`, `transaction_date`, `reference`, `description`, `supplier_name`, `category_account_code`, `category_account_name`, `amount_excl_cents`, `vat_amount_cents`, `total_cents`, `currency` (from journal lines; typically ZAR), `vat_claimable`, `receipt_filename`

**Receipt filename pattern:**

```
{transaction_date}_{sanitized_supplier_or_description}_{total_cents/100}.{ext}
```

Example: `2026-05-03_Supplier-Name_R4500.00.pdf`

Expenses without receipts: register row with empty `receipt_filename`; listed in `gaps.txt`.

### Payments

| Artifact | Rule |
|----------|------|
| Register row | Payment date in range (use payment `created_at` date or dedicated payment date field if present) |

Include bank amount and company book amount columns where stored (FX payments). Link to `invoice_number`.

### Bank transactions & statement files

| Artifact | Rule |
|----------|------|
| `bank-transactions.csv` | `banking_transactions.transaction_date` in range |
| Statement file | Include `banking_statement_imports` row if **any** of its transactions have `transaction_date` in range |

**Statement file naming:**

```
{year}-{month}_{sanitized_account_name}_{original_filename}
```

Read file from `Storage::disk('local')->path($import->stored_path)`; `original_filename` from DB.

**Bank transactions register columns (minimum):**

`transaction_date`, `account_name`, `description`, `reference`, `amount`, `currency`, `direction`, `import_original_filename`

### VAT periods

| Artifact | Rule |
|----------|------|
| Register row | Tax period where `[period_start, period_end]` overlaps `[from_date, to_date]` |

Include all statuses; columns include `status`, period dates, output/input/net VAT (reuse `VATReport::toArray()` fields per period).

### Contracts

**Data model note:** There is no `contract_id` on `invoices`. Inclusion is rule-based.

| Artifact | Rule |
|----------|------|
| Register row | Contract where **either**: (a) `[start_date, end_date]` overlaps `[from_date, to_date]` (`end_date` null = open-ended), **or** (b) `client_id` appears on any invoice in the takeout period |
| Signed PDF | `signed-contract` media collection if file exists |

**Register columns (minimum):**

`id`, `title`, `client_name`, `status`, `billing_type`, `start_date`, `end_date`, `contract_value_cents`, `signed_filename`

Contracts without signed file: register row + `gaps.txt` entry.

**Signed contract filename pattern:**

```
{start_date}_{sanitized_client_name}_{sanitized_title}.pdf
```

### Reports (figures)

| Report | Rule |
|--------|------|
| Income statement (P&L) | Reuse `ReportsController::profitLossData` for `[from_date, to_date]` |
| Trial balance | Reuse `LedgerService::trialBalance` as at `to_date` end of day |

Export account rows to CSV and xlsx (account code, name, type, debit, credit, balance).

---

## gaps.txt

Plain-text list, one issue per line, e.g.:

```
14 expense(s) without receipt attachments
Contract #3 "MSA Acme" — no signed file uploaded
No bank statement import covers 2026-03 for account "FNB Cheque"
```

Use the same detection logic as the pre-flight UI.

---

## Filename sanitisation

Shared helper `TakeoutFilename::sanitize(string $value): string`

- Allow: `a-zA-Z0-9._-`
- Replace other runs with `-`
- Collapse repeated `-`; trim; max length 80 chars per segment
- Preserve extension separately

---

## Code structure (proposed)

```
app/Domain/Takeout/
├── Models/TakeoutRun.php
├── Jobs/GenerateTakeoutJob.php
├── Services/
│   ├── TakeoutBuilder.php           # Orchestrates zip assembly
│   ├── TakeoutFigureExporter.php    # CSV + xlsx registers & reports
│   ├── TakeoutDocumentCollector.php # Copies/generates PDFs & media
│   └── TakeoutGapReporter.php
├── Support/TakeoutFilename.php
└── Notifications/
    ├── TakeoutReady.php
    └── TakeoutFailed.php

app/Http/Controllers/Web/Tax/
├── TaxDocumentsController.php       # Update: date range, pre-flight, list runs
└── TakeoutDownloadController.php    # Or single TakeoutController

app/Policies/TakeoutRunPolicy.php    # view/download/create: owner only
```

Reuse:

- `InvoicePdfService::renderToTemporaryPath`
- `ReportsController` profit/loss helpers (extract to shared service if needed to avoid HTTP coupling)
- `LedgerService::trialBalance`
- `VATReport`
- `ZipArchive` pattern from `InvoicePdfController::downloadZip`

---

## Routes

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tax/documents', ...)->name('tax.documents.index');
    Route::post('/tax/takeouts', [TakeoutController::class, 'store'])->name('tax.takeouts.store');
    Route::get('/tax/takeouts/{takeoutRun}/download', [TakeoutController::class, 'download'])
        ->name('tax.takeouts.download');
});
```

Download route uses `download_token` route key or query param — avoid enumerable IDs.

---

## Authorization

```php
// TakeoutRunPolicy
public function create(User $user, Team $team): bool
{
    return $user->ownsTeam($team);
}

public function download(User $user, TakeoutRun $run): bool
{
    return $user->ownsTeam($run->team);
}
```

Abort 403 for accountants/viewers on generate and download.

---

## Edge cases

| Case | Behaviour |
|------|-----------|
| Duplicate takeout request same period | Allow; each run is independent |
| Invoice PDF generation fails | Fail entire job with clear `error_message` (match bulk zip behaviour) |
| Missing media file on disk | Log warning; add to `gaps.txt`; continue job |
| Empty period | Still produce zip with empty registers and README noting zero rows |
| Very large zip | No hard limit v1; job runs on Horizon worker with adequate timeout |
| Run expires before download | Show expired in UI; user regenerates |

---

## Testing (required)

Feature tests:

1. Owner can queue takeout; accountant gets 403
2. Zip contains expected paths for seeded invoices, expenses, receipts
3. Voided invoice appears in register
4. Bank import included when only some transactions fall in range
5. Bank import excluded when no transactions in range
6. Contract included via client invoice link without date overlap
7. Contract included via date overlap without invoices
8. `gaps.txt` lists expense without receipt
9. Download rejected when expired or wrong token
10. CSV and xlsx pair have same row counts

Use `Storage::fake('local')` and mock PDF generation where needed.

---

## Changelog & roadmap

- User-facing: **feat(tax): add period data takeout export**
- Update `docs/ROADMAP.md` near-term: mark “Backup & restore docs” / tax pack as addressed or link this spec

---

## Future (out of scope v1)

- Email notification when takeout ready
- Accountant role download permission
- Checksums for every file in `manifest.json`
- PDF versions of P&L / trial balance (figures are CSV/xlsx only in v1)
- Scheduled annual reminder if no takeout for previous tax year

---

## Reference: SA tax year preset

```php
// Same logic as ReportsController::taxYearWindow
// Previous tax year: 1 March (year N) → last day of February (year N+1)
```

Presets are UI shortcuts only. The takeout period is always explicit `from_date` / `to_date` stored on `TakeoutRun`.
