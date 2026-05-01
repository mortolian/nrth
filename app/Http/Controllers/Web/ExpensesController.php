<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Actions\DeleteTransactionAction;
use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpensesController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Schema::hasTable('transactions')) {
            return Inertia::render('Expenses/Index', [
                'expenses' => new LengthAwarePaginator([], 0, 15),
                'summary' => [
                    'total_this_month' => 0,
                    'total_vat_claimable' => 0,
                    'awaiting_receipts' => 0,
                ],
                'categories' => [],
                'filters' => $this->filters($request),
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $start = (string) $request->string('from')->toString();
        $end = (string) $request->string('to')->toString();
        $categoryList = array_values(array_filter(explode(',', (string) $request->string('categories')->toString())));
        $supplier = trim((string) $request->string('supplier')->toString());
        $hasReceipt = (string) $request->string('has_receipt')->toString();
        $vatStatus = (string) $request->string('vat_status')->toString();

        $query = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->with(['journalEntries.account', 'taxLines', 'supplier'])
            ->withCount('media');

        if ($start !== '') {
            $query->whereDate('transaction_date', '>=', $start);
        }
        if ($end !== '') {
            $query->whereDate('transaction_date', '<=', $end);
        }
        if ($supplier !== '') {
            $query->where(function ($q) use ($supplier): void {
                $q->where('reference', 'like', '%'.$supplier.'%')
                    ->orWhere('description', 'like', '%'.$supplier.'%')
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', '%'.$supplier.'%'));
            });
        }
        if ($hasReceipt === 'yes') {
            $query->has('media');
        } elseif ($hasReceipt === 'no') {
            $query->doesntHave('media');
        }
        if (! empty($categoryList)) {
            $query->whereHas('journalEntries.account', fn ($q) => $q
                ->where('type', AccountType::Expense->value)
                ->whereIn('name', $categoryList));
        }
        if ($vatStatus === 'claimable') {
            $query->whereHas('taxLines', fn ($q) => $q->where('tax_amount_cents', '>', 0));
        } elseif ($vatStatus === 'non_claimable') {
            $query->whereDoesntHave('taxLines', fn ($q) => $q->where('tax_amount_cents', '>', 0));
        }

        $expenses = $query
            ->orderByDesc('transaction_date')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Transaction $transaction): array {
                $expenseLines = $transaction->journalEntries->filter(
                    fn ($entry) => $entry->account?->type === AccountType::Expense
                );
                $amountCents = (int) $expenseLines->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $category = (string) ($expenseLines->first()?->account?->name ?? 'Uncategorized');
                $vatAmount = (int) $transaction->taxLines->sum('tax_amount_cents');

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'supplier_id' => $transaction->supplier_id,
                    'supplier' => $transaction->supplier?->name
                        ?: ($transaction->reference ?: ($transaction->description ?: 'Unknown supplier')),
                    'category' => $category,
                    'description' => $transaction->description,
                    'amount' => $amountCents,
                    'vat_amount' => $vatAmount,
                    'status' => $transaction->status->value,
                    'has_receipt' => $transaction->media_count > 0,
                    'can_delete' => DeleteTransactionAction::canDelete($transaction),
                ];
            });

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $monthRows = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->with(['journalEntries.account', 'taxLines'])
            ->withCount('media')
            ->get();

        $totalThisMonth = $monthRows->sum(function (Transaction $transaction): int {
            return (int) $transaction->journalEntries
                ->filter(fn ($entry) => $entry->account?->type === AccountType::Expense)
                ->sum(fn ($entry) => (int) $entry->getRawOriginal('amount_cents'));
        });
        $totalVat = (int) $monthRows->sum(fn (Transaction $t): int => (int) $t->taxLines->sum('tax_amount_cents'));
        $awaitingReceipts = $monthRows->filter(fn (Transaction $t): bool => (int) $t->media_count === 0)->count();

        $categories = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->with('journalEntries.account')
            ->get()
            ->flatMap(fn (Transaction $transaction) => $transaction->journalEntries
                ->filter(fn ($entry) => $entry->account?->type === AccountType::Expense)
                ->map(fn ($entry) => $entry->account?->name))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'summary' => [
                'total_this_month' => $totalThisMonth,
                'total_vat_claimable' => $totalVat,
                'awaiting_receipts' => $awaitingReceipts,
            ],
            'categories' => $categories,
            'filters' => $this->filters($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $prefillSupplierId = (int) $request->integer('supplier_id');
        $prefillSupplierCustom = trim((string) $request->string('supplier')->toString());

        return Inertia::render('Expenses/Form', [
            'isEditing' => false,
            'expense' => null,
            'prefill' => [
                'supplier_id' => $prefillSupplierId > 0 ? $prefillSupplierId : 0,
                'supplier_custom' => $prefillSupplierId > 0 ? '' : $prefillSupplierCustom,
            ],
            'categories' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => trim($account->code.' - '.$account->name),
                ])
                ->all(),
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
            'sars_rate_per_km' => 4.84,
        ]);
    }

    public function edit(Request $request, Transaction $transaction): Response
    {
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $teamId = (int) $request->user()->current_team_id;

        return Inertia::render('Expenses/Form', [
            'isEditing' => true,
            'expense' => $this->serializeExpenseForForm($transaction),
            'prefill' => null,
            'categories' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => trim($account->code.' - '.$account->name),
                ])
                ->all(),
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
            'sars_rate_per_km' => 4.84,
        ]);
    }

    public function store(Request $request, PostTransactionAction $postTransactionAction): RedirectResponse
    {
        $teamId = (int) $request->user()->current_team_id;
        $userId = (int) $request->user()->id;
        $payload = $this->validatedExpensePayload($request, $teamId);
        $categoryAccount = $this->resolveCategoryAccount($teamId, $payload);
        $this->assertCategoryRules($categoryAccount, $payload);

        [$reference, $supplierIdToSave] = $this->resolveSupplier($payload, $teamId);
        $normalized = $this->normalizedExpenseAmounts($categoryAccount, $payload);
        $amountExclCents = $normalized[0];
        $vatAmountCents = $normalized[1];
        $isVatClaimable = $normalized[3];

        $creditAccount = $this->resolveCreditAccount($teamId, $payload['payment_method']);
        $vatInputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', '1200')
            ->first();

        $expenseMeta = $this->buildExpenseMeta($categoryAccount, $payload);

        $transaction = DB::transaction(function () use ($payload, $teamId, $userId, $categoryAccount, $creditAccount, $vatInputAccount, $isVatClaimable, $amountExclCents, $vatAmountCents, $postTransactionAction, $supplierIdToSave, $reference, $expenseMeta): Transaction {
            $transaction = Transaction::queryWithoutTeamScope()->create([
                'team_id' => $teamId,
                'supplier_id' => $supplierIdToSave,
                'type' => TransactionType::Expense,
                'status' => TransactionStatus::Draft,
                'reference' => $reference,
                'description' => $this->expenseDescriptionFromPayload($payload),
                'expense_meta' => $expenseMeta,
                'transaction_date' => $payload['date'],
                'created_by' => $userId,
            ]);

            $this->writeExpenseJournalAndTax(
                $transaction,
                $teamId,
                $payload,
                $categoryAccount,
                $creditAccount,
                $vatInputAccount,
                $isVatClaimable,
                $amountExclCents,
                $vatAmountCents,
                $reference
            );

            return $postTransactionAction->execute($transaction->fresh());
        });

        if ($request->hasFile('receipt')) {
            $transaction->clearMediaCollection('attachments');
            $transaction->addMediaFromRequest('receipt')->toMediaCollection('attachments');
        }

        return to_route('expenses.index');
    }

    public function update(Request $request, Transaction $transaction, PostTransactionAction $postTransactionAction): RedirectResponse
    {
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $teamId = (int) $request->user()->current_team_id;

        $payload = $this->validatedExpensePayload($request, $teamId);
        $categoryAccount = $this->resolveCategoryAccount($teamId, $payload);
        $this->assertCategoryRules($categoryAccount, $payload);

        [$reference, $supplierIdToSave] = $this->resolveSupplier($payload, $teamId);
        $normalized = $this->normalizedExpenseAmounts($categoryAccount, $payload);
        $amountExclCents = $normalized[0];
        $vatAmountCents = $normalized[1];
        $isVatClaimable = $normalized[3];

        $creditAccount = $this->resolveCreditAccount($teamId, $payload['payment_method']);
        $vatInputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', '1200')
            ->first();

        $expenseMeta = $this->buildExpenseMeta($categoryAccount, $payload);

        DB::transaction(function () use ($transaction, $payload, $teamId, $categoryAccount, $creditAccount, $vatInputAccount, $isVatClaimable, $amountExclCents, $vatAmountCents, $postTransactionAction, $supplierIdToSave, $reference, $expenseMeta): void {
            $transaction->forceFill([
                'status' => TransactionStatus::Draft,
                'supplier_id' => $supplierIdToSave,
                'reference' => $reference,
                'description' => $this->expenseDescriptionFromPayload($payload),
                'expense_meta' => $expenseMeta,
                'transaction_date' => $payload['date'],
            ]);
            $transaction->save();

            JournalEntry::query()->where('transaction_id', $transaction->id)->delete();
            TaxLine::query()->where('transaction_id', $transaction->id)->delete();

            $this->writeExpenseJournalAndTax(
                $transaction,
                $teamId,
                $payload,
                $categoryAccount,
                $creditAccount,
                $vatInputAccount,
                $isVatClaimable,
                $amountExclCents,
                $vatAmountCents,
                $reference
            );

            $postTransactionAction->execute($transaction->fresh());
        });

        if ($request->hasFile('receipt')) {
            $transaction->clearMediaCollection('attachments');
            $transaction->addMediaFromRequest('receipt')->toMediaCollection('attachments');
        }

        return to_route('expenses.index');
    }

    public function destroy(Request $request, Transaction $transaction, DeleteTransactionAction $deleteTransactionAction): RedirectResponse
    {
        $transaction = $this->resolveTeamExpense($request, $transaction);

        try {
            $deleteTransactionAction->execute($transaction);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return to_route('expenses.index');
    }

    public function storeReceipt(Request $request, Transaction $transaction): RedirectResponse
    {
        $transaction = $this->resolveTeamExpense($request, $transaction);
        $request->validate([
            'receipt' => ['required', 'file', 'max:10240'],
        ]);

        $transaction->addMediaFromRequest('receipt')->toMediaCollection('attachments');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedExpensePayload(Request $request, int $teamId): array
    {
        if ($request->has('supplier_id') && $request->string('supplier_id')->toString() === '') {
            $request->merge(['supplier_id' => null]);
        }

        return $request->validate([
            'date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $teamId)],
            'supplier' => ['required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'category_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('team_id', $teamId)],
            'description' => ['nullable', 'string'],
            'amount_excl_vat_cents' => ['required', 'integer', 'min:0'],
            'vat_rate' => ['required', Rule::in(['vat15', 'vat0', 'exempt', 'no_vat'])],
            'vat_amount_cents' => ['required', 'integer', 'min:0'],
            'payment_method' => ['required', Rule::in(['business_account', 'personal_reimbursable', 'credit_card'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'office_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'rate_per_km' => ['nullable', 'numeric', 'min:0'],
            'receipt' => ['nullable', 'file', 'max:10240'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCategoryAccount(int $teamId, array $payload): Account
    {
        return Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', AccountType::Expense->value)
            ->findOrFail((int) $payload['category_account_id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertCategoryRules(Account $categoryAccount, array $payload): void
    {
        $name = strtolower($categoryAccount->name);
        $isTravel = str_contains($name, 'travel');
        if ($isTravel) {
            $km = (float) ($payload['distance_km'] ?? 0);
            if ($km <= 0) {
                throw ValidationException::withMessages([
                    'distance_km' => __('Enter distance in kilometres for travel expenses.'),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int|null}
     */
    private function resolveSupplier(array $payload, int $teamId): array
    {
        $supplierId = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : 0;
        if ($supplierId > 0) {
            $supplierRow = Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereKey($supplierId)
                ->firstOrFail();

            return [$supplierRow->name, $supplierRow->id];
        }

        return [trim((string) ($payload['supplier'] ?? '')), null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: int, 2: string, 3: bool}
     */
    private function normalizedExpenseAmounts(Account $categoryAccount, array $payload): array
    {
        $name = strtolower($categoryAccount->name);
        $isHomeOffice = str_contains($name, 'home office');
        $isTravel = str_contains($name, 'travel');

        $excl = (int) $payload['amount_excl_vat_cents'];
        $vat = (int) $payload['vat_amount_cents'];
        $vatRate = (string) $payload['vat_rate'];

        if ($isTravel) {
            $km = (float) ($payload['distance_km'] ?? 0);
            $rate = (float) ($payload['rate_per_km'] ?? 0);
            $excl = (int) round($km * $rate * 100);
            $vat = 0;
            $vatRate = 'no_vat';
        } elseif ($isHomeOffice) {
            $pct = (float) ($payload['office_percentage'] ?? 0);
            $factor = max(0.0, min(100.0, $pct)) / 100.0;
            $excl = (int) round($excl * $factor);
            $vat = (int) round($vat * $factor);
        }

        $isVatClaimable = in_array($vatRate, ['vat15', 'vat0'], true) && $vat > 0;

        return [$excl, $vat, $vatRate, $isVatClaimable];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expenseDescriptionFromPayload(array $payload): ?string
    {
        $d = trim((string) ($payload['description'] ?? ''));
        $n = trim((string) ($payload['notes'] ?? ''));

        if ($d !== '') {
            return $d;
        }
        if ($n !== '') {
            return $n;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function buildExpenseMeta(Account $categoryAccount, array $payload): ?array
    {
        $name = strtolower($categoryAccount->name);
        $meta = [
            'payment_method' => $payload['payment_method'],
            'external_reference' => trim((string) ($payload['reference'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];
        if (str_contains($name, 'home office')) {
            $meta['office_percentage'] = (float) ($payload['office_percentage'] ?? 0);
        }
        if (str_contains($name, 'travel')) {
            $meta['distance_km'] = (float) ($payload['distance_km'] ?? 0);
            $meta['rate_per_km'] = (float) ($payload['rate_per_km'] ?? 0);
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCreditAccount(int $teamId, string $paymentMethod): Account
    {
        $creditCode = match ($paymentMethod) {
            'business_account' => '1010',
            'personal_reimbursable', 'credit_card' => '2000',
            default => '1010',
        };
        $creditAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', $creditCode)
            ->first();
        if ($creditAccount === null) {
            throw ValidationException::withMessages([
                'payment_method' => __('Missing required chart account for selected payment method.'),
            ]);
        }

        return $creditAccount;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeExpenseJournalAndTax(
        Transaction $transaction,
        int $teamId,
        array $payload,
        Account $categoryAccount,
        Account $creditAccount,
        ?Account $vatInputAccount,
        bool $isVatClaimable,
        int $amountExclCents,
        int $vatAmountCents,
        string $reference,
    ): void {
        $totalCents = $amountExclCents + $vatAmountCents;

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $categoryAccount->id,
            'type' => EntryType::Debit,
            'amount_cents' => $amountExclCents,
            'currency' => 'ZAR',
            'description' => 'Expense: '.($payload['description'] ?? $reference),
        ]);

        $creditAmount = $totalCents;
        if ($isVatClaimable && $vatInputAccount !== null) {
            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $vatInputAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => $vatAmountCents,
                'currency' => 'ZAR',
                'description' => 'VAT input claimable',
            ]);
        } else {
            $creditAmount = $amountExclCents;
        }

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $creditAccount->id,
            'type' => EntryType::Credit,
            'amount_cents' => $creditAmount,
            'currency' => 'ZAR',
            'description' => 'Expense payment',
        ]);

        $taxRate = TaxRate::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
        if ($vatAmountCents > 0 && $isVatClaimable && $taxRate !== null) {
            TaxLine::query()->create([
                'transaction_id' => $transaction->id,
                'tax_rate_id' => $taxRate->id,
                'taxable_amount_cents' => $amountExclCents,
                'tax_amount_cents' => $vatAmountCents,
                'type' => TaxLineType::Input,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExpenseForForm(Transaction $transaction): array
    {
        $transaction->loadMissing(['journalEntries.account', 'taxLines', 'supplier']);

        $expenseLine = $transaction->journalEntries->first(
            fn ($entry) => $entry->account?->type === AccountType::Expense
        );
        $creditLine = $transaction->journalEntries->first(
            fn ($entry) => $entry->type === EntryType::Credit
        );

        $amountExclCents = $expenseLine !== null
            ? (int) $expenseLine->getRawOriginal('amount_cents')
            : 0;
        $vatAmountCents = (int) $transaction->taxLines->sum('tax_amount_cents');

        $vatRate = 'no_vat';
        if ($vatAmountCents > 0) {
            $vatRate = 'vat15';
        } elseif ($transaction->taxLines->isNotEmpty()) {
            $vatRate = 'vat0';
        }

        $paymentMethod = 'business_account';
        if ($creditLine?->account?->code === '2000') {
            $paymentMethod = (string) ($transaction->expense_meta['payment_method'] ?? 'personal_reimbursable');
            if (! in_array($paymentMethod, ['personal_reimbursable', 'credit_card'], true)) {
                $paymentMethod = 'personal_reimbursable';
            }
        } elseif ($creditLine?->account?->code === '1010') {
            $paymentMethod = 'business_account';
        }

        $meta = $transaction->expense_meta ?? [];

        return [
            'id' => $transaction->id,
            'date' => optional($transaction->transaction_date)->toDateString(),
            'supplier_id' => $transaction->supplier_id ?? 0,
            'supplier_custom' => $transaction->supplier_id ? '' : (string) ($transaction->reference ?? ''),
            'category_account_id' => $expenseLine?->account_id ?? 0,
            'description' => (string) ($transaction->description ?? ''),
            'amount_excl_vat' => $amountExclCents / 100,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmountCents / 100,
            'payment_method' => $paymentMethod,
            'reference' => (string) ($meta['external_reference'] ?? ''),
            'notes' => (string) ($meta['notes'] ?? ''),
            'office_percentage' => (float) ($meta['office_percentage'] ?? 15),
            'distance_km' => (float) ($meta['distance_km'] ?? 0),
            'rate_per_km' => (float) ($meta['rate_per_km'] ?? 4.84),
        ];
    }

    private function resolveTeamExpense(Request $request, Transaction $transaction): Transaction
    {
        abort_unless($transaction->team_id === (int) $request->user()->current_team_id, 403);
        abort_unless($transaction->type === TransactionType::Expense, 404);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'categories' => array_values(array_filter(explode(',', (string) $request->string('categories')->toString()))),
            'supplier' => $request->string('supplier')->toString() ?: null,
            'has_receipt' => $request->string('has_receipt')->toString() ?: 'all',
            'vat_status' => $request->string('vat_status')->toString() ?: 'all',
        ];
    }
}
