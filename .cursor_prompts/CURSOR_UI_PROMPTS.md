# Cursor UI Prompts — Financial Intelligence Application
# Complete Frontend Pages & Components

All pages use: Vue 3 (Composition API + script setup), Inertia.js v2,
Tailwind CSS v4, shadcn-vue, vue-echarts, VeeValidate + Zod.
All data comes from Inertia page props unless stated otherwise.
All currency formatted as ZAR (R 1,234.56). All dates as DD MMM YYYY.
All pages use AppLayout.vue as the wrapping layout.

---

## UI PHASE 1 — App Shell & Layout (Foundation)

### UI-1.1 — App Layout & Sidebar
```
Build the main application shell in resources/js/Components/layout/

Create AppLayout.vue — the authenticated layout wrapping all pages.

Design direction: Refined, professional financial tool. Clean and calm.
Dark sidebar (#0f1117), white content area, ZAR green (#00a86b) as primary accent.
Subtle, not flashy — this is where accountants and business owners spend hours.

Sidebar (desktop, 260px wide, always visible on lg+):
- App logo/name top left
- Navigation sections:
  Overview: Dashboard
  Money In: Invoices, Quotes, Clients
  Money Out: Expenses, Suppliers
  Accounting: Transactions, Journal, Chart of Accounts
  Planning: Budgets
  Tax: VAT Returns, Tax Periods, Documents
  Contracting: Contracts, Time Tracking
  Reports: P&L, Balance Sheet, Cash Flow, Trial Balance
  Settings (bottom, pinned)

- Active state: green left border + light green background on nav item
- Icons: use lucide-vue-next throughout
- Team/company switcher below logo (Jetstream teams)
- Collapse to icon-only on md screens

Top bar:
- Breadcrumb (dynamic, passed as prop)
- Global search (Cmd+K trigger, opens command palette)
- Notifications bell
- User avatar + dropdown (profile, switch team, logout)

Mobile:
- Hamburger triggers slide-over sidebar overlay
- Bottom tab bar for 5 most common actions

Also create:
- PageHeader.vue — title, subtitle, action buttons slot
- StatCard.vue — metric, label, trend (up/down/neutral), trend percentage, icon
- AppTable.vue — sortable columns, pagination, row click handler, loading skeleton
- AppBadge.vue — status badge with variants: success, warning, danger, info, neutral
- MoneyDisplay.vue — formats ZAR amounts, red for negative, optional size prop
- DateDisplay.vue — formats dates DD MMM YYYY, relative time option (2 days ago)
- EmptyState.vue — icon, title, description, action button slot
- ConfirmDialog.vue — wraps shadcn AlertDialog, reusable confirmation modal
```

### UI-1.2 — Command Palette
```
Build a Cmd+K command palette in resources/js/Components/layout/CommandPalette.vue

Behaviour:
- Opens on Cmd+K (Mac) / Ctrl+K (Windows)
- Fuzzy search across: pages (navigate), recent invoices, clients, transactions
- Keyboard navigation (arrow keys, enter, escape)
- Groups: Quick Actions, Recent, Navigation
- Quick actions: New Invoice, New Expense, Record Payment, New Client

Quick actions trigger Inertia visits or open modals.
Use shadcn-vue Dialog + Command components as the base.
Animate open/close with smooth scale + fade.
```

---

## UI PHASE 2 — Dashboard

### UI-2.1 — Dashboard Page
```
Build resources/js/Pages/Dashboard.vue

Controller passes as Inertia props:
- kpis: { revenue_mtd, outstanding_invoices, vat_liability, net_profit_mtd }
  each with: amount, trend_percentage, trend_direction (up/down/neutral)
- revenue_chart: array of { month, revenue, expenses } for last 6 months
- outstanding_invoices: paginated list (top 5) with client, number, amount, due_date, days_overdue
- recent_transactions: last 10 with date, description, account, amount, type
- budget_progress: array of { category, allocated, spent, percentage }
- vat_summary: { current_period, output_vat, input_vat, net_vat, due_date }

Layout:
Row 1: 4 StatCards (Revenue MTD, Outstanding, VAT Liability, Net Profit)
Row 2: Revenue vs Expenses bar chart (2/3 width) + VAT summary card (1/3 width)
Row 3: Outstanding invoices table (2/3 width) + Budget progress (1/3 width)
Row 4: Recent transactions (full width)

Revenue chart: grouped bar chart via vue-echarts
- ZAR on Y axis, month labels on X axis
- Green bars for revenue, red/coral bars for expenses
- Tooltip showing exact amounts on hover

Outstanding invoices table:
- Overdue rows highlighted with subtle red left border
- Days overdue shown as danger badge
- Quick "Record Payment" button per row opening a slide-over

Budget progress:
- Progress bar per category
- Color: green < 75%, amber 75-90%, red > 90%
- Show spent / allocated below bar

All cards have loading skeleton state (use Suspense or v-if with skeleton components).
```

---

## UI PHASE 3 — Invoicing

### UI-3.1 — Invoice List Page
```
Build resources/js/Pages/Invoicing/Invoices/Index.vue

Controller props:
- invoices: paginated (15 per page) with client name, number, issue_date,
  due_date, total, amount_due, status
- summary: { draft_count, sent_count, overdue_count, overdue_total }
- filters: current active filters

Top section:
- Summary strip: Draft (count), Sent (count), Overdue (count + total amount in red)
- "New Invoice" button (primary, top right)

Filters bar:
- Status filter (All, Draft, Sent, Overdue, Paid, Void) as segmented control
- Date range picker (issue date)
- Client search/select
- Amount range (min/max)
- Clear filters button

Table columns:
- Number (link to show page)
- Client name
- Issue date
- Due date (red + overdue badge if past due)
- Total (ZAR)
- Amount Due (ZAR, bold if > 0)
- Status badge
- Actions: View, Send, Record Payment, Void (contextual based on status)

Row click navigates to invoice show page.
Bulk actions: Export selected (PDF zip), Mark as sent.
Pagination at bottom.
```

### UI-3.2 — Invoice Create / Edit Page
```
Build resources/js/Pages/Invoicing/Invoices/Form.vue
Used for both create and edit (isEditing prop).

Controller props:
- invoice: existing invoice data (null for create)
- clients: list for select dropdown
- tax_rates: list (VAT 15%, VAT 0%, Exempt)
- accounts: income accounts for account mapping
- next_number: next invoice number (for create)

Form layout (two column on desktop):

Left column (2/3 width) — Line items:
- Client selector (searchable, with "Add new client" option)
- Invoice number (auto-populated, editable)
- Reference field (optional PO number etc)
- Issue date + Due date (auto-calculated from client payment terms)

Line items table:
- Columns: Description, Qty, Unit Price, VAT Rate, VAT Amount, Total, Delete
- Add line item button below table
- Each row fully editable inline
- VAT calculated reactively as user types
- Drag to reorder lines (vue-draggable)

Totals panel (below line items):
- Subtotal (excl VAT)
- VAT breakdown per rate (if mixed rates)
- Total (incl VAT)
- Amount paid (on edit, if payments exist)
- Amount due (bold, prominent)

Right column (1/3 width) — Details:
- Notes textarea (appears on invoice)
- Footer textarea (appears at invoice bottom, e.g. banking details)
- Attachments (drag and drop, via MediaLibrary)
- Invoice preview button (opens PDF in new tab)

Bottom action bar (sticky):
- Save as Draft
- Save and Send (triggers SendInvoiceAction + email)
- Cancel

Validation via VeeValidate + Zod.
Totals recalculate reactively, no server round-trips.
```

### UI-3.3 — Invoice Show Page
```
Build resources/js/Pages/Invoicing/Invoices/Show.vue

Controller props:
- invoice: full invoice with client, line items, payments, activity log
- can: { edit, send, void, record_payment } — authorization flags

Layout:

Top action bar (contextual by status):
- Draft: Edit, Send Invoice, Delete
- Sent: Record Payment, Send Reminder, Void
- Partial: Record Payment, Send Reminder
- Paid: Download PDF, Duplicate
- Void: Duplicate only

Main content (two column):

Left (2/3):
- Invoice preview panel — styled to match the PDF output exactly
  Company details, client details, line items table, totals
  "This is a preview — download PDF for the official document"

Right (1/3):
- Status card with timeline: Created → Sent → Viewed → Paid
- Client details card with email/phone
- Payment history: list of payments with date, amount, method
- Record Payment button (opens slide-over)
- Activity log (sent, viewed, payment recorded etc)
- Attachments list

Record Payment slide-over:
- Amount (pre-filled with amount due)
- Payment date
- Payment method (EFT, Cash, Card, Other)
- Reference/notes
- Confirm button
Submits to RecordPaymentController, closes on success.
```

### UI-3.4 — Client List & Form Pages
```
Build resources/js/Pages/Invoicing/Clients/Index.vue
and resources/js/Pages/Invoicing/Clients/Form.vue

Index page:
- Client cards (grid view) or table (toggle)
- Card shows: name, email, outstanding balance, last invoice date, status badge
- Search by name or email
- Filter by active/inactive
- Click opens show page

Client Form (create + edit):
Fields:
- Company name, Contact name
- Email, Phone
- VAT number (validates SA VAT format: 10 digits starting with 4)
- Company registration number
- Address (street, city, province, postal code, country)
- Currency (default ZAR)
- Payment terms (days, default 30)
- Notes

Client Show page:
- Client details summary
- Invoice history table (same as invoice index but filtered)
- Outstanding balance, total invoiced, total paid stats
- Quick actions: New Invoice, New Quote
```

---

## UI PHASE 4 — Expenses

### UI-4.1 — Expense List Page
```
Build resources/js/Pages/Expenses/Index.vue

Controller props:
- expenses: paginated with date, supplier, category, amount, vat_amount, status, has_receipt
- summary: { total_this_month, total_vat_claimable, awaiting_receipts }
- categories: list for filter

Top summary strip:
- Total expenses (MTD)
- VAT claimable (MTD) — this is important for SA tax
- Expenses missing receipts (warning count, red if > 0)

Filters:
- Date range
- Category (multi-select)
- Supplier search
- Has receipt (Yes/No/All)
- VAT status (Claimable/Non-claimable/All)

Table columns:
- Date
- Supplier
- Category (badge)
- Description
- Amount (excl VAT)
- VAT amount (claimable shown in green)
- Receipt (paperclip icon if attached, warning icon if missing)
- Actions: Edit, Attach Receipt, Delete

"New Expense" button.
Bulk actions: Export to Excel (for accountant), Mark as reviewed.
```

### UI-4.2 — Expense Form Page
```
Build resources/js/Pages/Expenses/Form.vue

Fields:
- Date
- Supplier (searchable, create new inline)
- Category (select from chart of accounts expense accounts)
- Description
- Amount (excl VAT)
- VAT rate (VAT 15% / VAT 0% / Exempt / No VAT)
- VAT amount (auto-calculated, editable for override)
- Total (incl VAT, auto-calculated)
- Payment method (Business account, Personal reimbursable, Credit card)
- Reference/notes
- Receipt attachment (drag and drop, camera capture on mobile)
  Show thumbnail preview of attached receipt image/PDF

For home office and travel expenses add contextual fields:
- If category = Home Office: office percentage slider (e.g. 15% of home used for office)
- If category = Travel: distance (km), rate per km (SARS rate auto-populated),
  calculated deduction amount, with note "Keep logbook for SARS compliance"

Validation via VeeValidate + Zod.
Submit creates expense and posts journal entries.
```

---

## UI PHASE 5 — Accounting

### UI-5.1 — Transaction List Page
```
Build resources/js/Pages/Accounting/Transactions/Index.vue

Controller props:
- transactions: paginated with date, type, reference, description, status, total_amount
- filters: active filters

This is the master ledger view — all financial events.

Filters:
- Date range
- Type (Invoice, Payment, Expense, Transfer, Journal Adjustment)
- Status (Draft, Posted, Void)
- Account (select from chart of accounts)
- Search (reference, description)

Table columns:
- Date
- Type badge
- Reference (link to source — invoice, expense etc)
- Description
- Accounts affected (debit account → credit account)
- Amount
- Status badge (Posted green, Draft amber, Void grey strikethrough)
- Actions: View journal entries, Void (if posted)

Click row expands inline to show journal entry lines:
  Account | Type (Dr/Cr) | Amount
  Always shows balanced pairs.

Export button: Export to Excel for accountant.
```

### UI-5.2 — Chart of Accounts Page
```
Build resources/js/Pages/Accounting/ChartOfAccounts/Index.vue

Display accounts in hierarchical tree grouped by type:
ASSETS
  ├── 1000 · Cash & Bank
  ├── 1100 · Accounts Receivable
  └── 1200 · VAT Input Account
LIABILITIES
  ├── 2000 · Accounts Payable
  └── 2100 · VAT Output Account
EQUITY ...
INCOME ...
EXPENSES ...

Each row shows: Code | Name | Type | Current Balance | Actions

Actions:
- Edit name/description (not code or type for system accounts)
- Add sub-account
- Deactivate (if no transactions)
- View account statement

"Add Account" button opens form slide-over:
- Account code (auto-suggested, editable)
- Name
- Type (Asset/Liability/Equity/Income/Expense)
- Parent account (optional, for sub-accounts)
- Description

System accounts (marked with lock icon) cannot be deleted or have type changed.
Balance column shows current balance coloured:
- Assets/Expenses: green if debit balance (normal)
- Liabilities/Equity/Income: green if credit balance (normal)
- Red if abnormal balance (potential data issue)
```

### UI-5.3 — Account Statement Page
```
Build resources/js/Pages/Accounting/Accounts/Statement.vue

Shows all journal entries for a single account over a date range.

Controller props:
- account: account details
- entries: paginated journal entries with date, transaction reference,
  description, debit, credit, running_balance
- opening_balance: balance at start of period
- closing_balance: balance at end of period
- period: { from, to }

Layout:
- Account name + type + code as header
- Period selector (date range picker with presets)
- Opening balance row (shaded)
- Journal entries table:
  Date | Reference | Description | Debit | Credit | Balance
- Closing balance row (shaded, bold)
- Totals row: total debits, total credits

Export to PDF (formatted bank-statement style) for accountant.
Export to Excel.

Running balance column shows:
- Green if normal balance for account type
- Red if abnormal balance
```

---

## UI PHASE 6 — Budgeting

### UI-6.1 — Budget List & Overview
```
Build resources/js/Pages/Budgeting/Index.vue

Controller props:
- budgets: list with name, period, total_allocated, total_spent, percentage_used, status
- active_budget: current active budget with full breakdown
- monthly_variance: array of { month, budgeted, actual, variance } for active budget

Active budget section (top):
- Large progress indicator showing overall spend vs budget
- Grid of budget categories each showing:
  - Category name
  - Allocated amount
  - Spent amount
  - Remaining amount
  - Progress bar (green/amber/red based on percentage)
  - Trend arrow (spending faster or slower than last period)

Variance chart:
- Line chart: budgeted (dashed line) vs actual (solid line) per month
- Below chart: months where over budget highlighted in red

Past budgets table below.
"New Budget" button.
```

### UI-6.2 — Budget Form Page
```
Build resources/js/Pages/Budgeting/Form.vue

Create/edit a budget.

Fields:
- Budget name (e.g. "2025 Annual Budget", "Q1 2025")
- Period type: Monthly | Quarterly | Annual | Custom
- Start date, End date
- Currency (default ZAR)

Budget lines section:
- Table with one row per expense category (from chart of accounts)
- Columns: Category | Monthly Amount | Annual Total (auto × 12)
- Pre-populated with all active expense accounts
- User fills in the monthly allocation per category
- Running total shown at bottom
- Option to copy from previous budget period

"Import from last period" button auto-fills amounts from previous budget.
Save button creates budget and all BudgetLine records.
```

---

## UI PHASE 7 — Tax

### UI-7.1 — VAT Return Page
```
Build resources/js/Pages/Tax/VAT/Index.vue

Controller props:
- current_period: active VAT period with dates and status
- vat_summary: { output_vat, input_vat, net_vat, transaction_count }
- periods: list of past VAT periods with status and amounts
- vat_transactions: paginated list of transactions with VAT for current period

Layout:

Current period card (prominent):
- Period dates (e.g. 1 March 2025 — 30 April 2025)
- Due date (with countdown if < 14 days)
- Status badge

VAT201 summary panel (mirrors SARS VAT201 form layout):
  Output VAT (VAT collected on sales):     R XX,XXX.XX
  Less: Input VAT (VAT paid on purchases): R XX,XXX.XX
  ─────────────────────────────────────────────────────
  Net VAT payable to SARS:                 R XX,XXX.XX  ← bold, prominent
  (or: VAT refund due from SARS if negative)

Action buttons:
- View supporting transactions
- Export VAT report (Excel in SARS VAT201 format)
- Mark as submitted (records submission date)

Supporting transactions table (below):
- All transactions with VAT in the period
- Columns: Date | Reference | Description | Excl VAT | VAT Rate | VAT Amount | Type (Input/Output)
- Totals row confirming the summary above

Past periods table showing history and submission status.
```

### UI-7.2 — Tax Documents Page
```
Build resources/js/Pages/Tax/Documents/Index.vue

A document library for tax-ready supporting documents.

Sections:

1. Tax Year Summary (generate button)
   - Annual Income Statement (P&L formatted for tax purposes)
   - Full year trial balance
   - VAT summary for all periods in tax year
   - Generates PDF package for accountant

2. Document Categories (grid of cards):
   - Invoices (count, total value)
   - Expense receipts (count, flagging any missing)
   - VAT returns (submitted periods)
   - Contracts
   - Bank statements (uploaded)
   - SARS correspondence (upload area)

3. Generate Tax Pack button:
   Compiles all documents for a selected tax year into a single PDF package
   including: P&L, Balance Sheet, Trial Balance, VAT returns, schedule of assets
   Shows progress indicator while generating (can take a few seconds)

4. Missing documents checklist:
   Highlights any expenses without receipts
   Highlights any invoices without signed contracts
   Warns if bank statements not uploaded for any months
```

### UI-7.3 — Provisional Tax Page
```
Build resources/js/Pages/Tax/Provisional/Index.vue

Controller props:
- tax_year: current SA tax year (1 Mar - 28 Feb)
- periods: first and second provisional periods with due dates
- income_estimate: estimated annual taxable income
- previous_year_tax: last year's assessed tax (for safe harbour calculation)
- payments: recorded provisional tax payments

Layout:

Tax year header: "2024/2025 Tax Year (1 March 2024 — 28 February 2025)"

Two period cards side by side:
  First Period (due 31 August):
  - Status: Upcoming / Paid / Overdue
  - Estimated taxable income to date
  - Suggested payment (50% of estimated annual)
  - Safe harbour amount (90% of last year's tax ÷ 2)
  - "Record Payment" button

  Second Period (due 28 February):
  - Same structure
  - Shows actual full year income if available

Income estimate section:
- YTD actual income (from P&L)
- Projected annual (extrapolated)
- User can override with manual estimate
- Tax calculation at current SARS individual tax tables
- Note: "Consult your accountant for final provisional tax amounts"

SA tax tables (current year rates) used for rough calculation.
Clear disclaimer that this is an estimate only.
```

---

## UI PHASE 8 — Contracting

### UI-8.1 — Client Contracts Page
```
Build resources/js/Pages/Contracting/Contracts/Index.vue
and resources/js/Pages/Contracting/Contracts/Form.vue

Index:
- Contracts table: client, title, start date, end date, value, status, billing type
- Status: Draft, Active, Expired, Terminated
- Billing types: Fixed, Time & Materials, Retainer
- Filter by status, client, date range
- "New Contract" button

Form fields:
- Client (select)
- Contract title
- Contract type (Fixed Price / Time & Materials / Monthly Retainer)
- Start date, End date (optional for ongoing)
- Contract value (for Fixed) OR hourly rate (for T&M) OR monthly amount (Retainer)
- Payment terms
- Scope of work (rich text — use a simple contenteditable or textarea)
- Attach signed contract document (PDF upload via MediaLibrary)

For Retainer contracts:
- "Generate Invoice" button creates a recurring invoice automatically
- Show next invoice due date
```

### UI-8.2 — Time Tracking Page
```
Build resources/js/Pages/Contracting/Time/Index.vue

For time and materials contracts.

Timer section (top):
- Large active timer display (HH:MM:SS)
- Start/Stop/Pause button
- Client + Contract selector
- Description field
- Today's total hours

Time entries table:
- Date | Client | Contract | Description | Duration | Rate | Amount | Billable toggle
- Filter by client, contract, date range, billable/non-billable
- Edit inline
- Delete

Weekly summary:
- Hours per day (bar chart, simple)
- Total hours, billable hours, non-billable hours
- Estimated invoice value for unbilled hours

"Generate Invoice from Time" button:
- Select date range and contract
- Shows all unbilled time entries for that period
- Creates invoice with one line per day or one summarized line
- Marks time entries as billed
```

---

## UI PHASE 9 — Reports

### UI-9.1 — Profit & Loss Report
```
Build resources/js/Pages/Reports/ProfitLoss.vue

Controller props:
- report: ProfitLossReport data with income, expenses, net profit
- comparison: optional previous period data
- period: { from, to }

Controls:
- Period selector (This Month, Last Month, This Quarter, This Tax Year,
  Last Tax Year, Custom)
- Compare to: None, Previous Period, Same Period Last Year
- Export buttons: PDF, Excel

Report layout (mirrors standard P&L format):

INCOME
  Service Revenue          R xx,xxx.xx
  Other Income             R xx,xxx.xx
  ─────────────────────────────────────
  Total Income             R xx,xxx.xx    (bold)

EXPENSES
  Cost of Sales            R xx,xxx.xx
  Salaries                 R xx,xxx.xx
  ... (all expense accounts with balances)
  ─────────────────────────────────────
  Total Expenses           R xx,xxx.xx    (bold)

═══════════════════════════════════════
NET PROFIT / (LOSS)        R xx,xxx.xx    (large, green if profit, red if loss)

If comparison period selected, show two columns side by side with variance column.
Click any line item drills down to the transactions making up that amount.
```

### UI-9.2 — Balance Sheet Report
```
Build resources/js/Pages/Reports/BalanceSheet.vue

Controller props:
- report: BalanceSheetReport data
- as_of: date

Controls:
- As of date selector (defaults to today)
- Export PDF, Excel

Report layout:

ASSETS
  Current Assets
    Cash & Bank              R xx,xxx.xx
    Accounts Receivable      R xx,xxx.xx
    VAT Input Account        R xx,xxx.xx
  ─────────────────────────────────────
  Total Current Assets       R xx,xxx.xx
  ─────────────────────────────────────
  Total Assets               R xx,xxx.xx    (bold)

LIABILITIES & EQUITY
  Current Liabilities
    Accounts Payable         R xx,xxx.xx
    VAT Output Account       R xx,xxx.xx
  ─────────────────────────────────────
  Total Liabilities          R xx,xxx.xx
  Equity
    Owner's Equity           R xx,xxx.xx
    Retained Earnings        R xx,xxx.xx
  ─────────────────────────────────────
  Total Equity               R xx,xxx.xx
  ─────────────────────────────────────
  Total Liabilities + Equity R xx,xxx.xx    (bold, must equal Total Assets)

Show "Books are balanced ✓" or "WARNING: Books are not balanced ✗" prominently.
Click any line drills to account statement for that account.
```

### UI-9.3 — Trial Balance Report
```
Build resources/js/Pages/Reports/TrialBalance.vue

Controller props:
- report: TrialBalanceReport with all accounts and balances
- as_of: date

Table layout:
Account Code | Account Name | Debit | Credit

Grouped by account type with subtotals.
Footer row: Total Debits | Total Credits (must be equal).

Show large green "✓ Balanced" or red "✗ Unbalanced — difference: R X" at bottom.

Export to Excel (standard trial balance format accountants expect).
Export to PDF.
```

### UI-9.4 — Cash Flow Report
```
Build resources/js/Pages/Reports/CashFlow.vue

Controller props:
- report: CashFlowSummaryReport
- period: { from, to }

Three sections:

Operating Activities (cash from business operations)
  Net Profit                           R xx,xxx.xx
  Add: Depreciation                    R xx,xxx.xx
  Changes in working capital:
    Increase in Receivables           (R xx,xxx.xx)
    Decrease in Payables              (R xx,xxx.xx)
  ─────────────────────────────────────────────────
  Net Cash from Operating Activities   R xx,xxx.xx

Investing Activities
  Purchase of Equipment               (R xx,xxx.xx)
  ─────────────────────────────────────────────────
  Net Cash from Investing Activities  (R xx,xxx.xx)

Financing Activities (owner contributions/drawings)
  Owner Drawings                      (R xx,xxx.xx)
  ─────────────────────────────────────────────────
  Net Cash from Financing Activities  (R xx,xxx.xx)

═════════════════════════════════════════════════════
Net Change in Cash                     R xx,xxx.xx
Opening Cash Balance                   R xx,xxx.xx
─────────────────────────────────────────────────────
Closing Cash Balance                   R xx,xxx.xx    (bold, matches bank balance)

Period selector and export buttons same as other reports.
```

---

## UI PHASE 10 — Settings

### UI-10.1 — Company Settings
```
Build resources/js/Pages/Settings/Company.vue

Sections (tabbed or single scroll page):

Company Profile:
- Company name
- Trading name (if different)
- Registration number
- VAT number (validates SA format)
- Tax reference number (SARS)
- Industry type
- Financial year end (default: February, SA standard)
- Logo upload (shown on invoices and reports)

Contact Details:
- Physical address
- Postal address (or "same as physical")
- Email, phone, website

Invoice Defaults:
- Default payment terms (days)
- Invoice prefix (INV-)
- Next invoice number (editable to sync with existing numbering)
- Default notes (pre-fill on new invoices)
- Default footer (banking details — shown on every invoice)
- Email subject and body template

Tax Settings:
- VAT registered (yes/no toggle)
- VAT number
- VAT period type (2 monthly — SA standard for small vendors)
- Default VAT rate

Banking Details (shown on invoices):
- Bank name
- Account holder
- Account number
- Branch code
- Account type (Current/Savings)
```

### UI-10.2 — User & Team Settings
```
Build resources/js/Pages/Settings/Team.vue

Team Members section:
- List current team members with role (Owner, Accountant, Viewer)
- Invite by email with role selection
- Revoke access
- Role permissions summary:
  Owner: full access
  Accountant: view + export, cannot delete transactions
  Viewer: read-only

Roles managed via spatie/laravel-permission.
Use Jetstream's built-in team invitation flow.

Personal Settings (resources/js/Pages/Settings/Profile.vue):
- Name, email (Jetstream default, extend it)
- Password change
- Two-factor authentication
- Notification preferences:
  Invoice overdue reminders
  VAT period due reminders
  Provisional tax due reminders
  (Email toggles per notification type)
- Default date format preference
- Theme preference (light/dark/system)
```

---

## UI PHASE 11 — Onboarding

### UI-11.1 — First Run Setup Wizard
```
Build resources/js/Pages/Onboarding/Setup.vue

Multi-step wizard shown to new users after registration.
Progress indicator at top (Step X of 5).

Step 1 — Welcome
- Brief explanation of what the app does
- "Let's set up your company" CTA

Step 2 — Company Details
- Company name (required)
- VAT number (optional — show "Are you VAT registered?" toggle)
- Financial year end (default February)
- Industry type

Step 3 — Opening Balances
- "Do you have existing books?" toggle
- If yes: simple form for opening balances per main account
  (Cash/Bank balance, Accounts Receivable, Accounts Payable)
- If no: skip (start from zero)
- Note: "You can add detailed opening balances from Chart of Accounts later"

Step 4 — Invoice Setup
- Company logo upload
- Banking details (for invoice footer)
- Default payment terms
- Starting invoice number

Step 5 — Done
- Summary of what was set up
- Quick links: Create first invoice, Add first expense, Explore reports
- Confetti animation on completion

Skip option available throughout (takes straight to dashboard).
Progress saved between steps (store in session/local state).
```

---

## UI PHASE 12 — Mobile Optimisation

### UI-12.1 — Mobile-Specific Components
```
Audit and optimise the following pages specifically for mobile (375px+):

Priority pages for mobile use:
1. Dashboard — must be readable and useful on phone
2. Expense Form — most likely used on-the-go to capture receipts
3. Invoice List — quick status check
4. Time Tracking — start/stop timer while working

For Expense Form on mobile specifically:
- Camera button to capture receipt immediately (use input accept="image/*" capture="environment")
- Large touch targets on all buttons (min 48px height)
- Amount field opens numeric keyboard (inputmode="decimal")
- Date defaults to today
- Minimal fields shown, advanced options collapsed

Bottom navigation bar (mobile only, fixed):
- Dashboard | Invoices | + (quick add: expense or invoice) | Reports | More

Quick add sheet (triggered by + button):
- New Expense (most common)
- New Invoice
- Record Payment
- Start Timer

These should feel native — fast, tappable, minimal scrolling.
```