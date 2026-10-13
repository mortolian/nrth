<?php

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ExpenseReportService
{
    public const MAX_PDF_TRANSACTIONS = 1500;

    /**
     * @return array{
     *     start_year: int,
     *     label: string,
     *     from: string,
     *     to: string,
     *     is_current: bool
     * }
     */
    public function window(Team $team, ?int $startYear): array
    {
        return ReportPeriod::window($team, $startYear);
    }

    /**
     * @return list<array{start_year: int, label: string, from: string, to: string}>
     */
    public function yearOptions(Team $team): array
    {
        return ReportPeriod::yearOptions($team);
    }

    /**
     * @return array{
     *     accounts: list<array{account_id: int, code: string, name: string, amount: int}>,
     *     totals: array{expenses: int}
     * }
     */
    public function summary(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $entries = $this->expenseEntries($teamId, $from, $to);

        $accounts = $entries
            ->groupBy('account_id')
            ->map(function (Collection $rows): array {
                $account = $rows->first()->account;
                $amount = $this->netExpenseCents($rows);

                return [
                    'account_id' => (int) $account->id,
                    'code' => (string) $account->code,
                    'name' => (string) $account->name,
                    'amount' => $amount,
                ];
            })
            ->filter(fn (array $row): bool => $row['amount'] !== 0)
            ->sortBy('code')
            ->values()
            ->all();

        return [
            'accounts' => $accounts,
            'totals' => [
                'expenses' => (int) array_sum(array_column($accounts, 'amount')),
            ],
        ];
    }

    public function currency(Team $team): string
    {
        return ReportPeriod::currency($team);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $teamId, CarbonInterface $from, CarbonInterface $to, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->expenseTransactionsQuery($teamId, $from, $to)
            ->with($this->transactionRelations())
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            collect($this->present($paginator->getCollection()))
        );

        return $paginator;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactionsForExport(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $transactions = $this->expenseTransactionsQuery($teamId, $from, $to)
            ->with($this->transactionRelations())
            ->limit(self::MAX_PDF_TRANSACTIONS)
            ->get();

        return $this->present($transactions);
    }

    public function count(int $teamId, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->expenseTransactionsQuery($teamId, $from, $to)->count();
    }

    /**
     * @return Builder<Transaction>
     */
    private function expenseTransactionsQuery(int $teamId, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->forPeriodReporting()
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('journalEntries', function (Builder $entries) use ($teamId): void {
                $entries->whereHas('account', function (Builder $accounts) use ($teamId): void {
                    $accounts->withoutGlobalScopes()
                        ->where('team_id', $teamId)
                        ->where('type', AccountType::Expense->value);
                });
            })
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function expenseEntries(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return JournalEntry::query()
            ->whereHas('transaction', fn (Builder $query) => $query
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->forPeriodReporting()
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->whereHas('account', fn (Builder $query) => $query
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value))
            ->with([
                'account' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'code', 'name', 'type'),
                'transaction' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'transaction_date'),
            ])
            ->get();
    }

    /**
     * @return list<array{key: string, label: string, accounts: list<array{account_id: int, code: string, name: string, amount: int}>, total: int}>
     */
    public function monthly(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return MonthlyAccountBreakdown::build(
            $this->expenseEntries($teamId, $from, $to),
            $from,
            $to,
            function (JournalEntry $line): int {
                $cents = (int) $line->getRawOriginal('amount_cents');

                return $line->type === EntryType::Debit ? $cents : -$cents;
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionRelations(): array
    {
        return [
            'supplier:id,name',
            'creator:id,name',
            'journalEntries' => fn ($query) => $query->orderBy('id'),
            'journalEntries.account' => fn ($query) => $query->withoutGlobalScopes(),
            'taxLines.taxRate' => fn ($query) => $query->withoutGlobalScopes(),
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array<string, mixed>>
     */
    private function present(Collection $transactions): array
    {
        return $transactions
            ->map(fn (Transaction $transaction): array => $this->presentTransaction($transaction))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTransaction(Transaction $transaction): array
    {
        $meta = is_array($transaction->expense_meta) ? $transaction->expense_meta : [];
        $lines = [];
        $expenseAccounts = [];
        $expenseCents = 0;

        foreach ($transaction->journalEntries as $line) {
            $cents = (int) $line->getRawOriginal('amount_cents');
            $isExpense = $line->account?->type === AccountType::Expense;
            if ($isExpense) {
                $signed = $line->type === EntryType::Debit ? $cents : -$cents;
                $expenseCents += $signed;
                $accountId = (int) $line->account_id;
                $expenseAccounts[$accountId]['code'] = (string) ($line->account?->code ?? '');
                $expenseAccounts[$accountId]['name'] = (string) ($line->account?->name ?? '');
                $expenseAccounts[$accountId]['amount'] = ($expenseAccounts[$accountId]['amount'] ?? 0) + $signed;
            }

            $lines[] = [
                'account_code' => (string) ($line->account?->code ?? ''),
                'account_name' => (string) ($line->account?->name ?? ''),
                'account_type' => $line->account?->type?->value,
                'entry_type' => $line->type->value,
                'amount_cents' => $cents,
                'description' => $this->blankToNull($line->description),
                'is_expense' => $isExpense,
            ];
        }

        $taxLines = [];
        foreach ($transaction->taxLines as $taxLine) {
            $taxLines[] = [
                'type' => $taxLine->type->value,
                'type_label' => $taxLine->type->value === 'output' ? 'Output VAT' : 'Input VAT',
                'rate_name' => (string) ($taxLine->taxRate?->name ?? 'Tax'),
                'rate_percent' => $taxLine->taxRate?->rate_percent !== null
                    ? (string) $taxLine->taxRate->rate_percent
                    : null,
                'taxable_cents' => (int) $taxLine->taxable_amount_cents,
                'tax_cents' => (int) $taxLine->tax_amount_cents,
            ];
        }

        $notes = $this->blankToNull($meta['notes'] ?? null);
        $description = $this->blankToNull($transaction->description);
        if ($notes !== null && $notes === $description) {
            $notes = null;
        }

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->toDateString(),
            'reference' => $transaction->displayReference(),
            'description' => $description,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'status' => $transaction->status->value,
            'counterparty' => $transaction->displaySupplier(),
            'recorded_by' => $this->blankToNull($transaction->creator?->name),
            'paid_from' => $this->blankToNull($meta['paid_from_account_name'] ?? null),
            'notes' => $notes,
            'receipt_not_required' => (bool) $transaction->receipt_not_required,
            'distance_km' => $this->positiveNumber($meta['distance_km'] ?? null),
            'office_percentage' => $this->positiveNumber($meta['office_percentage'] ?? null),
            'expense_cents' => $expenseCents,
            'expense_accounts' => array_values($expenseAccounts),
            'lines' => $lines,
            'tax_lines' => $taxLines,
        ];
    }

    /**
     * @param  Collection<int, JournalEntry>  $rows
     */
    private function netExpenseCents(Collection $rows): int
    {
        $debit = (int) $rows
            ->where('type', EntryType::Debit)
            ->sum(fn (JournalEntry $line): int => (int) $line->getRawOriginal('amount_cents'));
        $credit = (int) $rows
            ->where('type', EntryType::Credit)
            ->sum(fn (JournalEntry $line): int => (int) $line->getRawOriginal('amount_cents'));

        return $debit - $credit;
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function positiveNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }
}
