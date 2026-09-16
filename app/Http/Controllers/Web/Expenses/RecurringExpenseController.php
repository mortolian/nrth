<?php

namespace App\Http\Controllers\Web\Expenses;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Services\BankingReconciliationTotals;
use App\Domain\Banking\Support\BankingPaymentAccounts;
use App\Domain\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Carbon\Carbon;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecurringExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('expenses.view', $request);
        $teamId = (int) $request->user()->current_team_id;
        $status = (string) $request->string('status')->toString();
        $search = trim((string) $request->string('search')->toString());

        $base = RecurringExpense::queryWithoutTeamScope()->where('team_id', $teamId);

        $summary = [
            'active' => (clone $base)->where('status', RecurringExpenseStatus::Active->value)->count(),
            'on_hold' => (clone $base)->where('status', RecurringExpenseStatus::OnHold->value)->count(),
            'completed' => (clone $base)->where('status', RecurringExpenseStatus::Completed->value)->count(),
            'due_soon' => (clone $base)
                ->where('status', RecurringExpenseStatus::Active->value)
                ->whereDate('next_run_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
        ];

        $query = RecurringExpense::queryWithoutTeamScope()
            ->with(['supplier:id,name', 'categoryAccount:id,code,name'])
            ->where('team_id', $teamId);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function ($q) use ($pattern): void {
                $q->where('supplier_name', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern)
                    ->orWhere('reference', 'like', $pattern)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $pattern));
            });
        }

        $rows = $query->orderBy('next_run_date')->paginate(25)->withQueryString()
            ->through(fn (RecurringExpense $row): array => $this->serializeList($row));

        return Inertia::render('Expenses/Recurring/Index', [
            'recurring' => $rows,
            'summary' => $summary,
            'filters' => [
                'status' => $status !== '' ? $status : 'all',
                'search' => $search !== '' ? $search : null,
            ],
        ]);
    }

    public function create(Request $request, BankingReconciliationTotals $totals): Response
    {
        $this->authorizeTeam('expenses.manage', $request);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        $teamId = (int) $team->id;
        $prefill = $this->resolveCreatePrefill($request, $team, $totals);

        return Inertia::render('Expenses/Recurring/Form', [
            'isEditing' => false,
            'recurring' => $prefill,
            ...$this->formMeta($teamId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $teamId = (int) $request->user()->current_team_id;
        $payload = $this->validatePayload($request, $teamId);

        $recurring = RecurringExpense::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
            'generated_count' => 0,
            'status' => RecurringExpenseStatus::Active->value,
        ]);

        return to_route('expenses.recurring.show', $recurring)
            ->with('success', __('Recurring expense created.'));
    }

    public function show(Request $request, RecurringExpense $recurringExpense): Response
    {
        $this->authorizeTeam('expenses.view', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->load([
            'supplier:id,name',
            'categoryAccount:id,code,name',
            'paidFromBankingAccount:id,name',
            'expenses' => fn ($q) => $q->latest('transaction_date')->limit(20),
        ]);

        return Inertia::render('Expenses/Recurring/Show', [
            'recurring' => $this->serializeDetail($recurringExpense),
            'can' => [
                'manage' => $request->user()->canOnTeam('expenses.manage', $request->user()->currentTeam),
                'delete' => $request->user()->canOnTeam('expenses.delete', $request->user()->currentTeam),
            ],
        ]);
    }

    public function edit(Request $request, RecurringExpense $recurringExpense): Response
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        return Inertia::render('Expenses/Recurring/Form', [
            'isEditing' => true,
            'recurring' => $this->serializeDetail($recurringExpense),
            ...$this->formMeta((int) $team->id),
        ]);
    }

    public function update(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $payload = $this->validatePayload($request, (int) $recurringExpense->team_id);
        $recurringExpense->update($payload);

        return to_route('expenses.recurring.show', $recurringExpense)
            ->with('success', __('Recurring expense updated.'));
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.delete', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->delete();

        return to_route('expenses.recurring.index')
            ->with('success', __('Recurring expense deleted.'));
    }

    public function pause(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->update(['status' => RecurringExpenseStatus::OnHold]);

        return back()->with('success', __('Recurring expense paused.'));
    }

    public function resume(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->update(['status' => RecurringExpenseStatus::Active]);

        return back()->with('success', __('Recurring expense resumed.'));
    }

    public function complete(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->update(['status' => RecurringExpenseStatus::Completed]);

        return back()->with('success', __('Recurring expense marked completed.'));
    }

    public function generateNow(Request $request, RecurringExpense $recurringExpense, GenerateRecurringExpenseAction $action): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);

        if ($recurringExpense->status !== RecurringExpenseStatus::Active) {
            $recurringExpense->update(['status' => RecurringExpenseStatus::Active]);
        }

        $expense = $action->execute(
            $recurringExpense->fresh(),
            Carbon::today(),
            (int) $request->user()->id,
        );
        if ($expense === null) {
            return back()->with('error', __('Could not generate expense. Check the template is active, the category and paid-from account are valid, and the supplier is active.'));
        }

        return to_route('expenses.edit', $expense)
            ->with('success', __('Expense generated from recurring template.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, int $teamId): array
    {
        $request->merge([
            'generate_on_last_day' => $request->boolean('generate_on_last_day'),
            'receipt_not_required' => $request->boolean('receipt_not_required'),
        ]);

        if ($request->has('supplier_id') && $request->string('supplier_id')->toString() === '') {
            $request->merge(['supplier_id' => null]);
        }

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $teamId)],
            'supplier' => ['required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'category_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('team_id', $teamId)
                    ->where('type', AccountType::Expense->value)
                    ->where('is_active', true)),
            ],
            'paid_from_banking_account_id' => [
                'required',
                'integer',
                Rule::exists('banking_accounts', 'id')->where(function ($query) use ($teamId): void {
                    $query->where('team_id', $teamId)
                        ->where('is_active', true)
                        ->whereNotNull('gl_account_id');
                }),
            ],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'generate_on_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'generate_on_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'generate_on_last_day' => ['required', 'boolean'],
            'generate_on_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'limit_type' => ['required', Rule::enum(RecurringLimitType::class)],
            'limit_count' => ['nullable', 'integer', 'min:1'],
            'limit_end_date' => ['nullable', 'date'],
            'next_run_date' => ['required', 'date'],
            'period_offset_months' => ['required', 'integer', 'min:-12', 'max:12'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'receipt_not_required' => ['required', 'boolean'],
            'reference' => ['nullable', 'string', 'max:255'],
            'amount_excl_vat_cents' => ['required', 'integer', 'min:0'],
            'vat_rate' => ['required', Rule::in(['vat15', 'vat0', 'exempt', 'no_vat'])],
            'vat_amount_cents' => ['required', 'integer', 'min:0'],
        ]);

        $category = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->findOrFail((int) $validated['category_account_id']);

        if ($this->isUnsupportedRecurringCategory($category)) {
            throw ValidationException::withMessages([
                'category_account_id' => __('Travel and home office categories need a one-off expense. Recurring templates are for fixed amounts such as rent or subscriptions.'),
            ]);
        }

        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : 0;

        return [
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'supplier_name' => $supplierId > 0 ? null : trim((string) ($validated['supplier'] ?? '')),
            'category_account_id' => (int) $validated['category_account_id'],
            'paid_from_banking_account_id' => (int) $validated['paid_from_banking_account_id'],
            'frequency' => $validated['frequency'],
            'generate_on_weekday' => $validated['generate_on_weekday'] ?? null,
            'generate_on_day' => $validated['generate_on_day'] ?? null,
            'generate_on_last_day' => (bool) $validated['generate_on_last_day'],
            'generate_on_month' => $validated['generate_on_month'] ?? null,
            'limit_type' => $validated['limit_type'],
            'limit_count' => $validated['limit_count'] ?? null,
            'limit_end_date' => $validated['limit_end_date'] ?? null,
            'next_run_date' => $validated['next_run_date'],
            'period_offset_months' => (int) $validated['period_offset_months'],
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'receipt_not_required' => (bool) $validated['receipt_not_required'],
            'reference' => $validated['reference'] ?? null,
            'amount_excl_vat_cents' => (int) $validated['amount_excl_vat_cents'],
            'vat_rate' => $validated['vat_rate'],
            'vat_amount_cents' => (int) $validated['vat_amount_cents'],
        ];
    }

    private function isUnsupportedRecurringCategory(Account $category): bool
    {
        $name = strtolower($category->name);

        return str_contains($name, 'travel') || str_contains($name, 'home office');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCreatePrefill(Request $request, Team $team, BankingReconciliationTotals $totals): ?array
    {
        $teamId = (int) $team->id;
        $expenseId = (int) $request->integer('expense_id');
        if ($expenseId > 0) {
            return $this->prefillFromExpense($expenseId, $team);
        }

        $bankLineId = (int) $request->integer('banking_transaction_id');
        if ($bankLineId > 0) {
            return $this->prefillFromBankLine($request, $bankLineId, $teamId, $totals);
        }

        $prefillSupplierId = (int) $request->integer('supplier_id');
        if ($prefillSupplierId < 1) {
            return null;
        }

        $supplierExists = Supplier::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereKey($prefillSupplierId)
            ->exists();

        if (! $supplierExists) {
            return null;
        }

        return [
            'supplier_id' => $prefillSupplierId,
            'supplier_custom' => '',
            'prefill_source' => 'supplier',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillFromExpense(int $expenseId, Team $team): array
    {
        $transaction = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense->value)
            ->with(['journalEntries.account', 'taxLines', 'supplier:id,name'])
            ->find($expenseId);

        abort_if($transaction === null, 404);

        $expenseLine = $transaction->journalEntries->first(
            fn ($entry) => $entry->account?->type === AccountType::Expense
        );
        $amountExclCents = $expenseLine !== null
            ? (int) $expenseLine->getRawOriginal('amount_cents')
            : 0;
        $vatAmountCents = $this->expenseVatAmountCents($transaction);
        $meta = $transaction->expense_meta ?? [];

        $categoryName = strtolower((string) ($expenseLine?->account?->name ?? ''));
        if (str_contains($categoryName, 'home office')) {
            if (isset($meta['entered_amount_excl_vat_cents'])) {
                $amountExclCents = (int) $meta['entered_amount_excl_vat_cents'];
                $vatAmountCents = (int) ($meta['entered_vat_amount_cents'] ?? 0);
            }
        }

        $vatRate = 'no_vat';
        if ($vatAmountCents > 0) {
            $vatRate = 'vat15';
        } elseif ($transaction->taxLines->isNotEmpty()) {
            $vatRate = 'vat0';
        }

        $categoryAccountId = (int) ($expenseLine?->account_id ?? 0);
        if ($categoryAccountId > 0 && $expenseLine?->account !== null && $this->isUnsupportedRecurringCategory($expenseLine->account)) {
            $categoryAccountId = 0;
        }

        $expenseDate = $transaction->transaction_date ?? now();
        $dayOfMonth = min(28, max(1, (int) $expenseDate->day));
        $isLastDay = (int) $expenseDate->day === (int) $expenseDate->copy()->endOfMonth()->day;

        return [
            'supplier_id' => $transaction->supplier_id ?? 0,
            'supplier_custom' => $transaction->supplier_id
                ? ''
                : (string) ($transaction->supplier?->name ?: ($transaction->reference ?? '')),
            'category_account_id' => $categoryAccountId,
            'paid_from_banking_account_id' => $this->paidFromBankingAccountIdFromExpense($transaction, $team),
            'frequency' => RecurringFrequency::Monthly->value,
            'generate_on_day' => $isLastDay ? 1 : $dayOfMonth,
            'generate_on_last_day' => $isLastDay,
            'next_run_date' => now()->toDateString(),
            'description' => (string) ($transaction->description ?? ''),
            'notes' => (string) ($meta['notes'] ?? ''),
            'receipt_not_required' => (bool) $transaction->receipt_not_required,
            'reference' => (string) ($meta['external_reference'] ?? ''),
            'amount_excl_vat_cents' => $amountExclCents,
            'vat_rate' => $vatRate,
            'vat_amount_cents' => $vatAmountCents,
            'prefill_source' => 'expense',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillFromBankLine(
        Request $request,
        int $bankLineId,
        int $teamId,
        BankingReconciliationTotals $totals,
    ): array {
        abort_unless($request->user()?->canOnTeam('banking.manage'), 403);

        $line = BankingTransaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->find($bankLineId);

        abort_if($line === null, 404);
        abort_unless($line->direction === TransactionDirection::Debit, 404);
        abort_if($line->reconciliation_status === ReconciliationStatus::Excluded, 404);

        $amountInclCents = max(
            $totals->bankAmountCents($line),
            $totals->remainingBankCents($line),
        );
        abort_if($amountInclCents < 1, 404);

        $exclCents = (int) round($amountInclCents / 1.15);
        $vatCents = $amountInclCents - $exclCents;
        $description = trim((string) $line->description);
        $matchedSupplier = $description === ''
            ? null
            : Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($description)])
                ->first();

        $txnDate = $line->transaction_date ?? now();
        $dayOfMonth = min(28, max(1, (int) $txnDate->day));
        $isLastDay = (int) $txnDate->day === (int) $txnDate->copy()->endOfMonth()->day;

        return [
            'supplier_id' => $matchedSupplier !== null ? (int) $matchedSupplier->id : 0,
            'supplier_custom' => $matchedSupplier !== null ? '' : Str::limit($description, 255, ''),
            'category_account_id' => 0,
            'paid_from_banking_account_id' => (int) $line->account_id,
            'frequency' => RecurringFrequency::Monthly->value,
            'generate_on_day' => $isLastDay ? 1 : $dayOfMonth,
            'generate_on_last_day' => $isLastDay,
            'next_run_date' => now()->toDateString(),
            'description' => $description,
            'notes' => '',
            'receipt_not_required' => false,
            'reference' => trim((string) ($line->reference ?? '')),
            'amount_excl_vat_cents' => $exclCents,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => $vatCents,
            'prefill_source' => 'banking',
        ];
    }

    private function expenseVatAmountCents(Transaction $transaction): int
    {
        $fromTaxLines = (int) $transaction->taxLines->sum('tax_amount_cents');
        if ($fromTaxLines > 0) {
            return $fromTaxLines;
        }

        return (int) $transaction->journalEntries
            ->filter(fn ($entry) => $entry->type === EntryType::Debit && $entry->account?->code === '1200')
            ->sum(fn ($entry) => (int) $entry->getRawOriginal('amount_cents'));
    }

    private function paidFromBankingAccountIdFromExpense(Transaction $transaction, Team $team): int
    {
        $meta = $transaction->expense_meta ?? [];
        $fromMeta = (int) ($meta['paid_from_banking_account_id'] ?? 0);
        if ($fromMeta > 0) {
            $existing = BankingAccount::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->whereKey($fromMeta)
                ->whereNotNull('gl_account_id')
                ->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }
        }

        $creditLine = $transaction->journalEntries->first(
            fn ($entry) => $entry->type === EntryType::Credit
        );
        $glAccountId = (int) ($creditLine?->account_id ?? 0);
        if ($glAccountId > 0) {
            $linked = BankingAccount::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->where('gl_account_id', $glAccountId)
                ->first();
            if ($linked !== null) {
                return (int) $linked->id;
            }
        }

        return (int) (new EnsureDefaultBankingAccount)->execute($team)->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function formMeta(int $teamId): array
    {
        return [
            'categories' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->reject(fn (Account $account) => $this->isUnsupportedRecurringCategory($account))
                ->values()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => trim($account->code.' - '.$account->name),
                ])
                ->all(),
            'paid_from_options' => BankingPaymentAccounts::forExpensePaidFrom($teamId),
            'supplier_options' => Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])
                ->all(),
            'tax_rates' => [
                ['value' => 'vat15', 'label' => 'VAT 15%', 'rate' => 0.15, 'claimable' => true],
                ['value' => 'vat0', 'label' => 'VAT 0%', 'rate' => 0.0, 'claimable' => true],
                ['value' => 'exempt', 'label' => 'Exempt', 'rate' => 0.0, 'claimable' => false],
                ['value' => 'no_vat', 'label' => 'No VAT', 'rate' => 0.0, 'claimable' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(RecurringExpense $row): array
    {
        return [
            'id' => $row->id,
            'supplier_name' => $row->displaySupplier(),
            'category' => $row->categoryAccount
                ? trim($row->categoryAccount->code.' - '.$row->categoryAccount->name)
                : 'Uncategorized',
            'description' => $row->description,
            'status' => $row->status->value,
            'frequency' => $row->frequency->value,
            'next_run_date' => optional($row->next_run_date)->toDateString(),
            'generated_count' => (int) $row->generated_count,
            'amount_excl_vat_cents' => (int) $row->amount_excl_vat_cents,
            'vat_amount_cents' => (int) $row->vat_amount_cents,
            'total_cents' => (int) $row->amount_excl_vat_cents + (int) $row->vat_amount_cents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(RecurringExpense $row): array
    {
        return [
            ...$this->serializeList($row),
            'supplier_id' => $row->supplier_id,
            'supplier_custom' => $row->supplier_id ? '' : (string) ($row->supplier_name ?? ''),
            'category_account_id' => $row->category_account_id,
            'paid_from_banking_account_id' => $row->paid_from_banking_account_id,
            'paid_from_name' => $row->paidFromBankingAccount?->name,
            'generate_on_weekday' => $row->generate_on_weekday,
            'generate_on_day' => $row->generate_on_day,
            'generate_on_last_day' => (bool) $row->generate_on_last_day,
            'generate_on_month' => $row->generate_on_month,
            'limit_type' => $row->limit_type->value,
            'limit_count' => $row->limit_count,
            'limit_end_date' => optional($row->limit_end_date)->toDateString(),
            'period_offset_months' => (int) $row->period_offset_months,
            'notes' => $row->notes,
            'receipt_not_required' => (bool) $row->receipt_not_required,
            'reference' => $row->reference,
            'vat_rate' => $row->vat_rate,
            'last_generated_at' => optional($row->last_generated_at)?->toIso8601String(),
            'expenses' => $row->relationLoaded('expenses')
                ? $row->expenses->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'description' => $transaction->description,
                    'status' => $transaction->status->value,
                ])->values()->all()
                : [],
        ];
    }
}
