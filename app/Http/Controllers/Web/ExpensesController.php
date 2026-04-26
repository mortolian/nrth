<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\RedirectResponse;
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
            ->with(['journalEntries.account', 'taxLines'])
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
                    ->orWhere('description', 'like', '%'.$supplier.'%');
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

        $expenses = $query
            ->orderByDesc('transaction_date')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Transaction $transaction) use ($vatStatus): ?array {
                $expenseLines = $transaction->journalEntries->filter(
                    fn ($entry) => $entry->account?->type === AccountType::Expense
                );
                $amountCents = (int) $expenseLines->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $category = (string) ($expenseLines->first()?->account?->name ?? 'Uncategorized');
                $vatAmount = (int) $transaction->taxLines->sum('tax_amount_cents');
                $claimable = $vatAmount > 0;

                if ($vatStatus === 'claimable' && ! $claimable) {
                    return null;
                }
                if ($vatStatus === 'non_claimable' && $claimable) {
                    return null;
                }

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'supplier' => $transaction->reference ?: ($transaction->description ?: 'Unknown supplier'),
                    'category' => $category,
                    'description' => $transaction->description,
                    'amount' => $amountCents,
                    'vat_amount' => $vatAmount,
                    'status' => $transaction->status->value,
                    'has_receipt' => $transaction->media_count > 0,
                ];
            });

        $filtered = array_values(array_filter($expenses->items()));
        $expenses->setCollection(collect($filtered));

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

        return Inertia::render('Expenses/Form', [
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
            'suppliers' => Transaction::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', TransactionType::Expense->value)
                ->whereNotNull('reference')
                ->select('reference')
                ->distinct()
                ->orderBy('reference')
                ->pluck('reference')
                ->filter()
                ->values()
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
        $payload = $request->validate([
            'date' => ['required', 'date'],
            'supplier' => ['required', 'string', 'max:255'],
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

        $categoryAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', AccountType::Expense->value)
            ->findOrFail((int) $payload['category_account_id']);

        $creditCode = match ($payload['payment_method']) {
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

        $vatInputAccount = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('code', '1200')
            ->first();
        $isVatClaimable = in_array($payload['vat_rate'], ['vat15', 'vat0'], true) && (int) $payload['vat_amount_cents'] > 0;
        $totalCents = (int) $payload['amount_excl_vat_cents'] + (int) $payload['vat_amount_cents'];

        $transaction = DB::transaction(function () use ($payload, $teamId, $userId, $categoryAccount, $creditAccount, $vatInputAccount, $isVatClaimable, $totalCents, $postTransactionAction): Transaction {
            $transaction = Transaction::queryWithoutTeamScope()->create([
                'team_id' => $teamId,
                'type' => TransactionType::Expense,
                'status' => 'draft',
                'reference' => $payload['supplier'],
                'description' => $payload['description'] ?? $payload['notes'] ?? null,
                'transaction_date' => $payload['date'],
                'created_by' => $userId,
            ]);

            JournalEntry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $categoryAccount->id,
                'type' => EntryType::Debit,
                'amount_cents' => (int) $payload['amount_excl_vat_cents'],
                'currency' => 'ZAR',
                'description' => 'Expense: '.($payload['description'] ?? $payload['supplier']),
            ]);

            $creditAmount = $totalCents;
            if ($isVatClaimable && $vatInputAccount !== null) {
                JournalEntry::query()->create([
                    'transaction_id' => $transaction->id,
                    'account_id' => $vatInputAccount->id,
                    'type' => EntryType::Debit,
                    'amount_cents' => (int) $payload['vat_amount_cents'],
                    'currency' => 'ZAR',
                    'description' => 'VAT input claimable',
                ]);
            } else {
                $creditAmount = (int) $payload['amount_excl_vat_cents'];
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
            if ((int) $payload['vat_amount_cents'] > 0 && $isVatClaimable && $taxRate !== null) {
                TaxLine::query()->create([
                    'transaction_id' => $transaction->id,
                    'tax_rate_id' => $taxRate->id,
                    'taxable_amount_cents' => (int) $payload['amount_excl_vat_cents'],
                    'tax_amount_cents' => (int) $payload['vat_amount_cents'],
                    'type' => TaxLineType::Input,
                ]);
            }

            return $postTransactionAction->execute($transaction->fresh());
        });

        if ($request->hasFile('receipt')) {
            $transaction->addMediaFromRequest('receipt')->toMediaCollection('attachments');
        }

        return to_route('expenses.index');
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
