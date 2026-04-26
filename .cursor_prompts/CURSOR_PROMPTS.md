# Cursor Prompts — Financial Intelligence Application

Use these prompts in sequence. Each builds on the previous.
Always run prompts in Cursor's **Composer** (not Chat) for file generation.

---

## PHASE 1 — Project Scaffold

### 1.1 — Laravel + Jetstream + Inertia Setup
```
Scaffold a new Laravel 13 application with the following setup:

1. Install Laravel Jetstream with the Inertia stack and Teams enabled
2. Install and configure Inertia.js v2 with Vue 3
3. Install Tailwind CSS v4
4. Install shadcn-vue
5. Install and configure the following packages:
   - brick/money
   - spatie/laravel-permission
   - spatie/laravel-activitylog
   - spatie/laravel-medialibrary
   - spatie/laravel-backup
   - spatie/laravel-pdf
   - maatwebsite/laravel-excel
   - laravel/pennant
   - laravel/cashier
   - laravel/horizon
   - laravel/sanctum
   - barryvdh/laravel-ide-helper

6. Install Vue dependencies: vue-echarts, pinia, vee-validate, zod, dayjs

Create the full directory structure as defined in the project rules.
Create a HasTeamScope trait in app/Domain/Shared that adds a global Eloquent scope
filtering all queries by the authenticated user's current_team_id.
```

### 1.2 — Docker Compose Self-Hosted Setup
```
Create a complete Docker Compose self-hosted distribution for this Laravel application.

Requirements:
- Services: app (Laravel Octane + Swoole), worker (queue), scheduler (cron), postgres, redis 7, minio
- All services use a single custom Laravel Docker image defined in docker/Dockerfile
- The app service runs Laravel Octane on port 8000
- MinIO configured as S3-compatible storage, accessible on port 9000 (API) and 9001 (console)
- Environment variables use sensible self-hosted defaults in .env.example
- Health checks on all services
- Named volumes for mysql data, redis data, minio data, and storage
- A single `docker compose up -d` starts the full stack

Also create:
- docker/Dockerfile (PHP 8.4, Swoole, all required extensions)
- docker/entrypoint.sh (runs migrations on first start, starts Octane)
- docker/supervisord.conf (manages Octane, worker, scheduler in worker container)
- .env.example with self-hosted defaults (MinIO, local mail, SQLite option)
```

### 1.3 — Install Artisan Commands
```
Create two Artisan commands for self-hosted distribution:

1. `php artisan app:install`
   - Checks system requirements (PHP version, extensions)
   - Runs migrations
   - Seeds the default chart of accounts (South African standard accounts)
   - Creates the first admin user interactively (name, email, password, company name)
   - Generates app key if not set
   - Creates default tax rates (VAT 15%, VAT 0%, VAT Exempt)
   - Outputs a success message with the app URL

2. `php artisan app:update`
   - Puts app in maintenance mode
   - Runs pending migrations
   - Clears and rebuilds caches (config, route, view)
   - Restarts queue workers
   - Takes app out of maintenance mode
   - Outputs a changelog summary for the current version

Both commands should have clear console output using Laravel's command IO methods.
```

---

## PHASE 2 — Double-Entry Accounting Core

### 2.1 — Chart of Accounts
```
Build the Chart of Accounts domain in app/Domain/Accounting.

Create the following:

Enums:
- AccountType: Asset, Liability, Equity, Income, Expense
  - Include a normalBalance() method returning 'debit' or 'credit'
  - Include an isDebit() and isCredit() helper

Models:
- Account
  - Fields: id, team_id, parent_id (nullable), code, name, description,
    type (AccountType enum), is_system (bool), is_active (bool), timestamps
  - Relationships: parent(), children(), journalEntries()
  - Apply HasTeamScope trait
  - System accounts cannot be deleted or renamed

Migrations:
- accounts table with all fields, indexes on team_id and code

Seeders:
- DefaultChartOfAccountsSeeder
  South African standard chart of accounts including:
  Assets: Cash/Bank (1000s), Accounts Receivable (1100), VAT Input (1200)
  Liabilities: Accounts Payable (2000), VAT Output (2100), Income Tax Payable (2200)
  Equity: Owner's Equity (3000), Retained Earnings (3100)
  Income: Service Revenue (4000), Other Income (4900)
  Expenses: Cost of Sales (5000), Salaries (5100), Rent (5200),
    Utilities (5300), Travel (5400), Home Office (5500),
    Professional Fees (5600), Bank Charges (5700), Depreciation (5800)

Actions:
- CreateAccountAction (CreateAccountDTO)
- UpdateAccountAction (UpdateAccountDTO)
- DeactivateAccountAction

Factory:
- AccountFactory with states for each AccountType
```

### 2.2 — Journal Entry Engine
```
Build the core journal entry engine in app/Domain/Accounting.

Create the following:

Enums:
- EntryType: Debit, Credit
- TransactionStatus: Draft, Posted, Void
- TransactionType: Invoice, Payment, Expense, Transfer, JournalAdjustment, OpeningBalance

Models:
- Transaction
  - Fields: id, team_id, type (TransactionType), status (TransactionStatus),
    reference, description, transaction_date, posted_at, voided_at,
    voided_reason (nullable), created_by (user_id), timestamps
  - Relationships: journalEntries(), taxLines(), attachments() (via MediaLibrary)
  - Apply HasTeamScope trait

- JournalEntry
  - Fields: id, transaction_id, account_id, type (EntryType),
    amount_cents (integer), currency (string, default ZAR), description, timestamps
  - Relationships: transaction(), account()
  - Add a money() accessor returning a brick/money Money object
  - NO team_id — scoped through transaction relationship

- TaxLine
  - Fields: id, transaction_id, tax_rate_id, taxable_amount_cents,
    tax_amount_cents, type (Input/Output), timestamps
  - Relationships: transaction(), taxRate()

Custom Cast:
- MoneyCast — converts amount_cents integer to/from brick/money Money object (ZAR default)

Services:
- LedgerService
  - getBalance(Account $account, ?Carbon $asOf = null): Money
  - getAccountStatement(Account $account, Carbon $from, Carbon $to): Collection
  - isBalanced(Transaction $transaction): bool
  - trialBalance(Team $team, Carbon $asOf): Collection

Actions:
- PostTransactionAction
  - Validates journal entries are balanced (debits === credits)
  - Sets status to Posted and posted_at timestamp
  - Throws UnbalancedTransactionException if not balanced

- VoidTransactionAction
  - Creates reversing journal entries
  - Sets original transaction status to Void

Tests:
- Feature tests for PostTransactionAction covering:
  - Happy path: balanced entries post successfully
  - Throws exception when entries are unbalanced
  - Void creates correct reversing entries
  - Balance calculated correctly after posting
```

### 2.3 — Financial Reports
```
Build the core financial report classes in app/Domain/Accounting/Reports.

Create the following report classes (each returns structured data, not a view):

1. TrialBalanceReport
   - Input: Team $team, Carbon $asOf
   - Output: Collection of accounts with debit/credit balances
   - Must verify debits === credits (balanced books check)

2. ProfitLossReport (Income Statement)
   - Input: Team $team, Carbon $from, Carbon $to, ?Carbon $compareTo
   - Output: Income totals, expense totals, net profit, comparison period if provided
   - Group by account type and parent account

3. BalanceSheetReport
   - Input: Team $team, Carbon $asOf
   - Output: Assets, Liabilities, Equity totals and line items
   - Must verify Assets === Liabilities + Equity

4. CashFlowSummaryReport
   - Input: Team $team, Carbon $from, Carbon $to
   - Output: Operating, investing, financing cash flows

Each report class should:
- Have an generate() method returning a typed DTO
- Be independently testable without HTTP
- Support ZAR currency formatting via brick/money
- Include a toArray() method for Inertia page props
```

---

## PHASE 3 — Invoicing Domain

### 3.1 — Client and Invoice Models
```
Build the Invoicing domain in app/Domain/Invoicing.

Enums:
- InvoiceStatus: Draft, Sent, Viewed, Partial, Paid, Overdue, Void
- QuoteStatus: Draft, Sent, Accepted, Declined, Expired, Converted

Models:
- Client
  - Fields: id, team_id, name, email, phone, vat_number (nullable),
    registration_number (nullable), address (JSON), currency (default ZAR),
    payment_terms_days (default 30), notes, is_active, timestamps
  - Apply HasTeamScope trait
  - Relationships: invoices(), quotes(), contacts()

- Invoice
  - Fields: id, team_id, client_id, status (InvoiceStatus), number (auto-generated),
    reference (nullable), issue_date, due_date, subtotal_cents, vat_amount_cents,
    total_cents, amount_paid_cents, currency, notes, footer, sent_at,
    viewed_at, paid_at, voided_at, timestamps
  - Apply HasTeamScope trait, MoneyCast on amount fields
  - Relationships: client(), lineItems(), payments(), transaction()
  - Accessors: amountDue(), isOverdue(), vatRate()

- InvoiceLineItem
  - Fields: id, invoice_id, description, quantity (decimal), unit_price_cents,
    vat_rate (decimal, e.g. 0.15), vat_amount_cents, total_cents, sort_order, timestamps
  - Relationships: invoice()
  - Methods: calculateVAT(), calculateTotal()

- Payment (against an invoice)
  - Fields: id, team_id, invoice_id, amount_cents, currency, payment_date,
    method (cash/eft/card/other), reference, notes, transaction_id, timestamps

Number Generator Service:
- InvoiceNumberService — generates sequential invoice numbers per team
  Format: INV-{YEAR}-{SEQUENCE} e.g. INV-2025-0001
  Thread-safe using database sequence or pessimistic locking

Actions:
- CreateInvoiceAction (CreateInvoiceDTO)
  - Creates invoice with line items
  - Calculates VAT per line item at 15% (or 0% for exempt items)
  - Does NOT post to ledger yet (only on payment)

- SendInvoiceAction
  - Sets status to Sent, records sent_at
  - Queues invoice PDF email to client
  - Creates activity log entry

- RecordPaymentAction (RecordPaymentDTO)
  - Creates Payment record
  - Creates Transaction + JournalEntries:
    Debit: Bank Account
    Credit: Accounts Receivable
    If VAT applicable: separate Output VAT journal line
  - Updates invoice status (Partial or Paid)
  - Posts transaction via PostTransactionAction

- VoidInvoiceAction
  - Only allowed on Draft or Sent invoices
  - Voids any associated transactions
  - Sets status to Void

Factories for all models.
Feature tests for all actions.
```

---

## PHASE 4 — Tax Domain (South African)

### 4.1 — VAT Engine
```
Build the Tax domain in app/Domain/Tax for South African compliance.

Models:
- TaxRate
  - Fields: id, team_id, name, rate (decimal), code (e.g. VAT15, VAT0, EXEMPT),
    is_default, is_active, timestamps
  - Apply HasTeamScope trait

- TaxPeriod
  - Fields: id, team_id, period_start, period_end, type (VAT/Provisional),
    status (Open/Submitted/Closed), due_date, submitted_at, notes, timestamps
  - Apply HasTeamScope trait
  - Relationships: vatReturn()

- VATReturn
  - Fields: id, team_id, tax_period_id, output_vat_cents, input_vat_cents,
    net_vat_cents (output - input), period_start, period_end, timestamps
  - Apply HasTeamScope trait

Services:
- VATService
  - calculateOutputVAT(Team $team, Carbon $from, Carbon $to): Money
    (VAT collected on sales/invoices)
  - calculateInputVAT(Team $team, Carbon $from, Carbon $to): Money
    (VAT paid on purchases/expenses)
  - calculateNetVAT(Team $team, Carbon $from, Carbon $to): Money
    (output - input, positive = owe SARS, negative = refund)
  - getVATSummary(Team $team, TaxPeriod $period): VATSummaryDTO

- ProvisionalTaxService
  - getCurrentTaxYear(Team $team): array [start, end]
    SA tax year: 1 March to 28/29 February
  - getProvisionalPeriods(int $taxYear): array of two periods
    First: 1 March to 31 August
    Second: 1 September to 28/29 February
  - estimateAnnualIncome(Team $team, int $taxYear): Money

Actions:
- GenerateVATReturnAction (TaxPeriod)
  - Calculates all VAT lines for the period
  - Creates VATReturn record
  - Marks TaxPeriod as submitted

- CreateTaxPeriodAction
  - Creates VAT periods (2 months default for SA VAT vendors)
  - Creates provisional tax periods (August and February)

Report:
- VATReport
  - Standard SARS VAT201 format fields
  - toArray() for Inertia
  - toExcel() for SARS eFiling export (using maatwebsite/excel)

Seeder:
- DefaultTaxRatesSeeder
  - VAT Standard Rate (15%) — default
  - VAT Zero Rate (0%)
  - VAT Exempt
  - VAT Outside Scope
```

---

## PHASE 5 — Inertia Frontend

### 5.1 — App Shell and Layout
```
Build the application shell in resources/js.

Create the following:

Layout Components:
- AppLayout.vue — main authenticated layout
  - Collapsible sidebar navigation
  - Top bar with team switcher, notifications, user menu
  - Breadcrumb support
  - Mobile responsive with slide-over sidebar

- Sidebar navigation structure:
  Dashboard | Invoicing (Invoices, Quotes, Clients) | Accounting (Transactions,
  Chart of Accounts, Journal) | Budgeting | Tax (VAT Returns, Tax Periods) |
  Contracting (Contracts, Time) | Reports | Settings

Composables:
- useFormatCurrency(amount: number, currency = 'ZAR'): string
  Format using South African locale (R 1,234.56)
- useDateRange(): { from, to, setPreset }
  Presets: This Month, Last Month, This Quarter, This Tax Year, Last Tax Year
- useFlash(): access Inertia flash messages
- usePagination(): handle Inertia paginated responses

Pinia Stores:
- useTeamStore — current team, plan, features
- useAuthStore — current user

Global Components to register:
- AppButton, AppInput, AppSelect, AppTable, AppCard, AppBadge
- StatCard (for dashboard KPIs)
- MoneyDisplay (formats ZAR amounts correctly)
- DateDisplay (formats dates in South African format DD MMM YYYY)

Use shadcn-vue primitives as the base for all components.
Apply the design system from the project frontend-design rules.
All components use TypeScript with proper prop types.
```

### 5.2 — Dashboard Page
```
Build the main dashboard Inertia page.

Controller: app/Http/Controllers/Web/DashboardController.php
- Returns current month P&L summary
- Returns outstanding invoices total
- Returns VAT due for current period
- Returns recent transactions (last 10)
- Returns budget vs actual for current month

Page: resources/js/Pages/Dashboard.vue

Dashboard sections:
1. KPI row: Total Revenue (MTD), Outstanding Invoices, VAT Liability,
   Net Profit (MTD) — each as a StatCard with trend vs last month

2. Revenue vs Expenses chart — bar chart using vue-echarts
   Last 6 months, grouped bars, ZAR amounts on Y axis

3. Outstanding invoices table — client name, invoice number,
   amount, due date, days overdue (highlighted red if overdue),
   quick action to record payment

4. Recent transactions list — date, description, account, amount

5. Budget progress — progress bars per budget category showing
   spent vs allocated for current month

All data comes from Inertia page props (no separate API calls).
Use South African date and currency formatting throughout.
```

---

## PHASE 6 — PDF Document Generation

### 6.1 — Invoice PDF
```
Build the invoice PDF generation system using spatie/laravel-pdf.

Create:

1. Invoice Blade template: resources/views/pdf/invoice.blade.php
   - Professional layout with company logo (from MediaLibrary)
   - Company details top left, client details top right
   - Invoice number, date, due date
   - Line items table: description, quantity, unit price, VAT, total
   - Subtotal, VAT amount, total due
   - Payment terms and banking details
   - Footer with registration number and VAT number
   - South African professional styling

2. InvoicePdfService in app/Domain/Invoicing/Services
   - generate(Invoice $invoice): pdf file
   - Uses spatie/laravel-pdf
   - Stores generated PDF via MediaLibrary attached to the invoice
   - Returns the stored media model

3. Update SendInvoiceAction to attach the generated PDF to the email

4. InvoicePdfController (Web)
   - download(Invoice $invoice): StreamedResponse
   - Authorizes that the invoice belongs to the current team

5. Mail class: InvoiceMailer
   - Professional email template
   - Attaches invoice PDF
   - Includes payment instructions and due date
```

---

## PHASE 7 — Self-Hosted Packaging

### 7.1 — Release Build Script
```
Create a build and release script for self-hosted distribution.

Create: scripts/build-release.sh

The script should:
1. Run tests (php artisan test) — abort if any fail
2. Build frontend assets (npm run build)
3. Run composer install --no-dev --optimize-autoloader
4. Generate optimized autoloader
5. Create a versioned release archive (.tar.gz) excluding:
   node_modules, .git, tests, .env, storage/app/*, storage/logs/*
6. Generate a SHA256 checksum file for the archive
7. Output the release filename and checksum

Also create: INSTALL.md
Self-hosted installation guide covering:
- Docker Compose installation (recommended)
- Manual installation (PHP 8.3, MySQL 8, Redis)
- Running php artisan app:install
- Configuring backups
- Updating to new versions with php artisan app:update
- Troubleshooting common issues
```

---

## ONGOING — Feature Development Prompt Template

Use this template for any new feature you build:

```
Build the [FEATURE NAME] feature following the project architecture rules.

Domain: app/Domain/[DomainName]

Requirements:
- [List what it does]
- [List the user-facing behaviour]

Create:
1. Enums: [list any new enums needed]
2. DTO: [FeatureName]DTO with fields [list fields]
3. Model: [ModelName] with fields [list fields], relationships [list], apply HasTeamScope
4. Migration: [describe the table]
5. Action: [ActionName] that [describe what it does including any journal entries created]
6. Controller: app/Http/Controllers/Web/[Domain]/[Controller].php (thin, delegates to action)
7. Inertia Page: resources/js/Pages/[Domain]/[Page].vue with [describe UI]
8. Form Request: [RequestName] with validation rules
9. Route: add to routes/web.php under [domain] route group
10. Factory: [ModelName]Factory
11. Test: Feature test for [ActionName] covering happy path and [list edge cases]

South African context: [any SA-specific rules, e.g. VAT treatment, tax codes]
Journal entries required: [list debit/credit pairs if financial]
```
