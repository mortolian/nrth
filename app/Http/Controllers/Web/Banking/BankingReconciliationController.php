<?php

namespace App\Http\Controllers\Web\Banking;

use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\AllocateBankingTransactionAction;
use App\Domain\Banking\Actions\ExcludeBankingTransactionAction;
use App\Domain\Banking\Actions\RemoveBankingTransactionAllocationAction;
use App\Domain\Banking\Actions\ResetBankingTransactionReconciliationAction;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use App\Domain\Banking\Services\BankingReconciliationTotals;
use App\Domain\Banking\Services\SuggestReconciliationCandidates;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class BankingReconciliationController extends Controller
{
    public function __construct(
        private readonly BankingReconciliationTotals $totals,
        private readonly SuggestReconciliationCandidates $candidates,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeTeam('banking.view', $request);

        if (! Schema::hasTable('banking_transactions')) {
            return Inertia::render('Banking/Transactions/Index', [
                'transactions' => new LengthAwarePaginator([], 0, 25),
                'selected' => null,
                'accounts' => [],
                'filters' => $this->filters($request),
                'counts' => [
                    'all' => 0,
                    'attention' => 0,
                    ReconciliationStatus::Unreviewed->value => 0,
                    ReconciliationStatus::PartiallyMatched->value => 0,
                    ReconciliationStatus::Matched->value => 0,
                    ReconciliationStatus::Excluded->value => 0,
                ],
                'can_manage' => $request->user()?->canOnTeam('banking.manage') ?? false,
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $filters = $this->filters($request);
        $status = (string) ($filters['status'] ?? 'all');

        $query = BankingTransaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with([
                'account:id,name,bank_name,currency,gl_account_id',
                'import:id,original_filename,created_at',
            ])
            ->withSum('allocations as allocated_cents', 'amount_cents');

        $this->applyFilters($query, $filters, $status);

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BankingTransaction $line): array => $this->serializeLine($line));

        $selectedId = (int) $request->integer('selected');
        $selected = null;
        if ($selectedId > 0) {
            $selectedLine = BankingTransaction::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->with([
                    'account:id,name,bank_name,currency,gl_account_id',
                    'import:id,original_filename,created_at',
                    'allocations.transaction.supplier',
                    'allocations.transaction.payments.invoice',
                    'allocations.transaction.journalEntries',
                ])
                ->find($selectedId);

            if ($selectedLine !== null) {
                $selected = $this->serializeSelected($selectedLine);
            }
        }

        $accounts = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name', 'currency'])
            ->map(fn (BankingAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'currency' => $account->currency,
            ])
            ->all();

        return Inertia::render('Banking/Transactions/Index', [
            'transactions' => $transactions,
            'selected' => $selected,
            'accounts' => $accounts,
            'filters' => $filters,
            'counts' => $this->counts($teamId, $filters),
            'can_manage' => $request->user()?->canOnTeam('banking.manage') ?? false,
        ]);
    }

    public function redirectToTransactions(Request $request): RedirectResponse
    {
        $this->authorizeTeam('banking.view', $request);

        return redirect()->route('banking.transactions.index', $request->query());
    }

    public function storeAllocation(
        Request $request,
        BankingTransaction $bankingTransaction,
        AllocateBankingTransactionAction $action,
    ): RedirectResponse {
        $this->authorizeTeam('banking.manage', $request);
        $this->assertTeamLine($request, $bankingTransaction);

        $validated = $request->validate([
            'transaction_id' => ['required', 'integer'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = Transaction::queryWithoutTeamScope()->find((int) $validated['transaction_id']);
        if ($transaction === null) {
            return back()->withErrors([
                'transaction_id' => __('That accounting transaction could not be found.'),
            ]);
        }

        $action->execute(
            $bankingTransaction,
            $transaction,
            (int) $validated['amount_cents'],
            (int) $request->user()->current_team_id,
            (int) $request->user()->id,
            $validated['note'] ?? null,
        );

        return back()->with('success', __('Bank line matched.'));
    }

    public function destroyAllocation(
        Request $request,
        BankingTransaction $bankingTransaction,
        BankingTransactionAllocation $allocation,
        RemoveBankingTransactionAllocationAction $action,
    ): RedirectResponse {
        $this->authorizeTeam('banking.manage', $request);
        $this->assertTeamLine($request, $bankingTransaction);
        abort_unless((int) $allocation->team_id === (int) $request->user()->current_team_id, 403);

        $action->execute($bankingTransaction, $allocation);

        return back()->with('success', __('Allocation removed.'));
    }

    public function exclude(
        Request $request,
        BankingTransaction $bankingTransaction,
        ExcludeBankingTransactionAction $action,
    ): RedirectResponse {
        $this->authorizeTeam('banking.manage', $request);
        $this->assertTeamLine($request, $bankingTransaction);

        $validated = $request->validate([
            'exclusion_note' => ['nullable', 'string', 'max:255'],
        ]);

        $action->execute(
            $bankingTransaction,
            (int) $request->user()->id,
            $validated['exclusion_note'] ?? null,
        );

        return back()->with('success', __('Bank line marked as excluded (personal / not business).'));
    }

    public function reset(
        Request $request,
        BankingTransaction $bankingTransaction,
        ResetBankingTransactionReconciliationAction $action,
    ): RedirectResponse {
        $this->authorizeTeam('banking.manage', $request);
        $this->assertTeamLine($request, $bankingTransaction);

        $action->execute($bankingTransaction);

        return back()->with('success', __('Bank line marked as unreviewed.'));
    }

    /**
     * @param  Builder<BankingTransaction>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters, string $status): void
    {
        if ($status === 'attention') {
            $query->whereIn('reconciliation_status', [
                ReconciliationStatus::Unreviewed->value,
                ReconciliationStatus::PartiallyMatched->value,
            ]);
        } elseif (in_array($status, array_map(fn (ReconciliationStatus $case) => $case->value, ReconciliationStatus::cases()), true)) {
            $query->where('reconciliation_status', $status);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('transaction_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('transaction_date', '<=', $filters['to']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('account_id', (int) $filters['account_id']);
        }
        if (! empty($filters['direction']) && in_array($filters['direction'], array_map(fn (TransactionDirection $d) => $d->value, TransactionDirection::cases()), true)) {
            $query->where('direction', $filters['direction']);
        }
        if (! empty($filters['search'])) {
            $pattern = '%'.mb_strtolower((string) $filters['search']).'%';
            $query->where(function ($q) use ($pattern): void {
                $q->whereRaw('LOWER(description) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$pattern]);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function counts(int $teamId, array $filters): array
    {
        $base = BankingTransaction::queryWithoutTeamScope()->where('team_id', $teamId);
        $this->applyFilters($base, [...$filters, 'status' => 'all'], 'all');

        $counts = [
            'all' => (clone $base)->count(),
            'attention' => (clone $base)->whereIn('reconciliation_status', [
                ReconciliationStatus::Unreviewed->value,
                ReconciliationStatus::PartiallyMatched->value,
            ])->count(),
            ReconciliationStatus::Unreviewed->value => (clone $base)->where('reconciliation_status', ReconciliationStatus::Unreviewed->value)->count(),
            ReconciliationStatus::PartiallyMatched->value => (clone $base)->where('reconciliation_status', ReconciliationStatus::PartiallyMatched->value)->count(),
            ReconciliationStatus::Matched->value => (clone $base)->where('reconciliation_status', ReconciliationStatus::Matched->value)->count(),
            ReconciliationStatus::Excluded->value => (clone $base)->where('reconciliation_status', ReconciliationStatus::Excluded->value)->count(),
        ];

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLine(BankingTransaction $line): array
    {
        $allocated = (int) ($line->getAttribute('allocated_cents') ?? $this->totals->allocatedBankCents($line));
        $amountCents = $this->totals->bankAmountCents($line);
        $status = $line->reconciliation_status instanceof ReconciliationStatus
            ? $line->reconciliation_status
            : ReconciliationStatus::from((string) $line->reconciliation_status);

        return [
            'id' => $line->id,
            'transaction_date' => $line->transaction_date?->format('Y-m-d'),
            'description' => $line->description,
            'reference' => $line->reference,
            'amount_cents' => $amountCents,
            'allocated_cents' => $allocated,
            'remaining_cents' => max(0, $amountCents - $allocated),
            'currency' => $line->currency,
            'direction' => $line->direction->value,
            'reconciliation_status' => $status->value,
            'reconciliation_status_label' => $status->label(),
            'account' => [
                'id' => $line->account->id,
                'name' => $line->account->name,
                'bank_name' => $line->account->bank_name,
            ],
            'import' => $line->import !== null ? [
                'id' => $line->import->id,
                'original_filename' => $line->import->original_filename,
                'imported_at' => $line->import->created_at?->format('Y-m-d H:i'),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSelected(BankingTransaction $line): array
    {
        $payload = $this->serializeLine($line);
        $payload['exclusion_note'] = $line->exclusion_note;
        $payload['excluded_at'] = $line->excluded_at?->format('Y-m-d H:i');
        $payload['allocations'] = $line->allocations
            ->map(function (BankingTransactionAllocation $allocation) use ($line): array {
                $transaction = $allocation->transaction;
                $remaining = $transaction !== null
                    ? $this->totals->remainingTransactionCents($transaction, $line, $allocation->id)
                    : 0;

                return [
                    'id' => $allocation->id,
                    'amount_cents' => (int) $allocation->amount_cents,
                    'note' => $allocation->note,
                    'transaction' => $transaction !== null ? $this->serializeTransaction($transaction, $line, $remaining) : null,
                ];
            })
            ->values()
            ->all();

        $payload['candidates'] = array_map(function (array $row) use ($line): array {
            /** @var Transaction $transaction */
            $transaction = $row['transaction'];

            return [
                ...$this->serializeTransaction($transaction, $line, (int) $row['remaining_cents']),
                'score' => (int) $row['score'],
                'suggested_amount_cents' => (int) $row['suggested_amount_cents'],
            ];
        }, $this->candidates->for($line));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(Transaction $transaction, BankingTransaction $bankLine, int $remainingCents): array
    {
        $invoiceNumber = $transaction->payments->first()?->invoice?->number;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'reference' => $transaction->displayReference(),
            'description' => $transaction->description,
            'supplier' => $transaction->displaySupplier(),
            'invoice_number' => $invoiceNumber,
            'matchable_cents' => $this->totals->matchableTransactionCents($transaction, $bankLine),
            'remaining_cents' => $remainingCents,
            'context_label' => $this->contextLabel($transaction, $invoiceNumber),
        ];
    }

    private function contextLabel(Transaction $transaction, ?string $invoiceNumber): string
    {
        if ($invoiceNumber !== null && $invoiceNumber !== '') {
            return $transaction->type->label().' · '.$invoiceNumber;
        }

        $supplier = $transaction->displaySupplier();
        if ($supplier !== null) {
            return $transaction->type->label().' · '.$supplier;
        }

        $reference = $transaction->displayReference();
        if ($reference !== null) {
            return $transaction->type->label().' · '.$reference;
        }

        return $transaction->type->label();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $status = (string) $request->string('status')->toString();
        $allowed = [
            'attention',
            'all',
            ...array_map(fn (ReconciliationStatus $case) => $case->value, ReconciliationStatus::cases()),
        ];
        if (! in_array($status, $allowed, true)) {
            $status = 'all';
        }

        return [
            'status' => $status,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'account_id' => $request->integer('account_id') ?: null,
            'direction' => $request->string('direction')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'selected' => $request->integer('selected') ?: null,
        ];
    }

    private function assertTeamLine(Request $request, BankingTransaction $bankingTransaction): void
    {
        abort_unless((int) $bankingTransaction->team_id === (int) $request->user()->current_team_id, 403);
    }
}
