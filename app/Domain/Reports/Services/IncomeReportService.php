<?php

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Support\FinancialYear;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class IncomeReportService
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
        $startMonth = $this->startMonth($team);
        [$currentStart] = FinancialYear::windowContaining(now(), $startMonth);

        $start = $currentStart->copy();
        if ($startYear !== null && $startYear >= 1970 && $startYear <= 2100) {
            $start = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        }

        $end = $start->copy()->addYear()->subDay()->startOfDay();

        return [
            'start_year' => (int) $start->year,
            'label' => FinancialYear::labelForWindow($start, $end),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'is_current' => $start->equalTo($currentStart),
        ];
    }

    /**
     * @return list<array{start_year: int, label: string, from: string, to: string}>
     */
    public function yearOptions(Team $team): array
    {
        $startMonth = $this->startMonth($team);
        [$currentStart] = FinancialYear::windowContaining(now(), $startMonth);
        $options = [];

        for ($i = 0; $i < 8; $i++) {
            $start = $currentStart->copy()->subYears($i)->startOfDay();
            $end = $start->copy()->addYear()->subDay()->startOfDay();
            $options[] = [
                'start_year' => (int) $start->year,
                'label' => FinancialYear::labelForWindow($start, $end),
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ];
        }

        return $options;
    }

    /**
     * @return array{
     *     accounts: list<array{account_id: int, code: string, name: string, amount: int}>,
     *     totals: array{income: int}
     * }
     */
    public function summary(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $entries = $this->incomeEntries($teamId, $from, $to);

        $accounts = $entries
            ->groupBy('account_id')
            ->map(function (Collection $rows): array {
                $account = $rows->first()->account;
                $amount = $this->netIncomeCents($rows);

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
                'income' => (int) array_sum(array_column($accounts, 'amount')),
            ],
        ];
    }

    public function currency(Team $team): string
    {
        $settings = $team->mergedBusinessSettings();

        return Iso4217Currencies::normalize(
            is_string($settings['invoice_default_currency'] ?? null)
                ? $settings['invoice_default_currency']
                : null
        );
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $teamId, CarbonInterface $from, CarbonInterface $to, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->incomeTransactionsQuery($teamId, $from, $to)
            ->with($this->transactionRelations())
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            collect($this->present($teamId, $paginator->getCollection()))
        );

        return $paginator;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactionsForExport(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $transactions = $this->incomeTransactionsQuery($teamId, $from, $to)
            ->with($this->transactionRelations())
            ->limit(self::MAX_PDF_TRANSACTIONS)
            ->get();

        return $this->present($teamId, $transactions);
    }

    public function count(int $teamId, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->incomeTransactionsQuery($teamId, $from, $to)->count();
    }

    private function startMonth(Team $team): int
    {
        $settings = $team->mergedBusinessSettings();
        $endMonth = (int) ($settings['financial_year_end_month'] ?? 2);

        return FinancialYear::startMonthFromEndMonth($endMonth);
    }

    /**
     * @return Builder<Transaction>
     */
    private function incomeTransactionsQuery(int $teamId, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->forPeriodReporting()
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('journalEntries', function (Builder $entries) use ($teamId): void {
                $entries->whereHas('account', function (Builder $accounts) use ($teamId): void {
                    $accounts->withoutGlobalScopes()
                        ->where('team_id', $teamId)
                        ->where('type', AccountType::Income->value);
                });
            })
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function incomeEntries(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection
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
                ->where('type', AccountType::Income->value))
            ->with(['account' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'code', 'name', 'type')])
            ->get();
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
    private function present(int $teamId, Collection $transactions): array
    {
        if ($transactions->isEmpty()) {
            return [];
        }

        $ids = $transactions->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $context = $this->counterpartyContext($teamId, $ids);

        return $transactions
            ->map(fn (Transaction $transaction): array => $this->presentTransaction($transaction, $context))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array{
     *     invoices: array<int, Invoice>,
     *     clients: array<int, string>,
     *     payments: array<int, Payment>
     * }
     */
    private function counterpartyContext(int $teamId, array $transactionIds): array
    {
        $payments = Payment::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereIn('transaction_id', $transactionIds)
            ->get()
            ->keyBy(fn (Payment $payment): int => (int) $payment->transaction_id);

        $invoices = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where(function (Builder $query) use ($transactionIds, $payments): void {
                $query->whereIn('accrual_transaction_id', $transactionIds)
                    ->orWhereIn('transaction_id', $transactionIds);

                $invoiceIds = $payments->pluck('invoice_id')->filter()->unique()->values();
                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhereIn('id', $invoiceIds->all());
                }
            })
            ->get(['id', 'number', 'client_id', 'accrual_transaction_id', 'transaction_id']);

        $byTransaction = [];
        foreach ($invoices as $invoice) {
            if ($invoice->accrual_transaction_id !== null) {
                $byTransaction[(int) $invoice->accrual_transaction_id] = $invoice;
            }
        }
        foreach ($invoices as $invoice) {
            if ($invoice->transaction_id !== null && ! isset($byTransaction[(int) $invoice->transaction_id])) {
                $byTransaction[(int) $invoice->transaction_id] = $invoice;
            }
        }
        foreach ($payments as $transactionId => $payment) {
            if (isset($byTransaction[(int) $transactionId]) || $payment->invoice_id === null) {
                continue;
            }
            $invoice = $invoices->firstWhere('id', $payment->invoice_id);
            if ($invoice !== null) {
                $byTransaction[(int) $transactionId] = $invoice;
            }
        }

        $clientIds = $invoices->pluck('client_id')->filter()->unique()->values();
        $clients = $clientIds->isEmpty()
            ? []
            : Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereIn('id', $clientIds->all())
                ->pluck('name', 'id')
                ->map(fn ($name): string => trim((string) $name))
                ->all();

        return [
            'invoices' => $byTransaction,
            'clients' => $clients,
            'payments' => $payments->all(),
        ];
    }

    /**
     * @param  array{
     *     invoices: array<int, Invoice>,
     *     clients: array<int, string>,
     *     payments: array<int, Payment>
     * }  $context
     * @return array<string, mixed>
     */
    private function presentTransaction(Transaction $transaction, array $context): array
    {
        $invoice = $context['invoices'][$transaction->id] ?? null;
        $clientName = $invoice?->client_id !== null
            ? ($context['clients'][(int) $invoice->client_id] ?? null)
            : null;
        $clientName = $clientName !== null && $clientName !== '' ? $clientName : null;
        $payment = $context['payments'][$transaction->id] ?? null;

        $lines = [];
        $incomeAccounts = [];
        $incomeCents = 0;

        foreach ($transaction->journalEntries as $line) {
            $cents = (int) $line->getRawOriginal('amount_cents');
            $isIncome = $line->account?->type === AccountType::Income;
            if ($isIncome) {
                $signed = $line->type === EntryType::Credit ? $cents : -$cents;
                $incomeCents += $signed;
                $accountId = (int) $line->account_id;
                $incomeAccounts[$accountId]['code'] = (string) ($line->account?->code ?? '');
                $incomeAccounts[$accountId]['name'] = (string) ($line->account?->name ?? '');
                $incomeAccounts[$accountId]['amount'] = ($incomeAccounts[$accountId]['amount'] ?? 0) + $signed;
            }

            $lines[] = [
                'account_code' => (string) ($line->account?->code ?? ''),
                'account_name' => (string) ($line->account?->name ?? ''),
                'account_type' => $line->account?->type?->value,
                'entry_type' => $line->type->value,
                'amount_cents' => $cents,
                'description' => $this->blankToNull($line->description),
                'is_income' => $isIncome,
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

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->toDateString(),
            'reference' => $transaction->displayReference(),
            'description' => $this->blankToNull($transaction->description),
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'status' => $transaction->status->value,
            'counterparty' => $clientName ?? $transaction->displaySupplier(),
            'recorded_by' => $this->blankToNull($transaction->creator?->name),
            'income_cents' => $incomeCents,
            'income_accounts' => array_values($incomeAccounts),
            'invoice' => $invoice === null ? null : [
                'number' => (string) $invoice->number,
            ],
            'payment' => $payment === null ? null : [
                'method_label' => $this->paymentMethodLabel($payment->method),
                'reference' => $this->blankToNull($payment->reference),
                'notes' => $this->blankToNull($payment->notes),
                'date' => $payment->payment_date?->toDateString(),
            ],
            'lines' => $lines,
            'tax_lines' => $taxLines,
        ];
    }

    /**
     * @param  Collection<int, JournalEntry>  $rows
     */
    private function netIncomeCents(Collection $rows): int
    {
        $credit = (int) $rows
            ->where('type', EntryType::Credit)
            ->sum(fn (JournalEntry $line): int => (int) $line->getRawOriginal('amount_cents'));
        $debit = (int) $rows
            ->where('type', EntryType::Debit)
            ->sum(fn (JournalEntry $line): int => (int) $line->getRawOriginal('amount_cents'));

        return $credit - $debit;
    }

    private function paymentMethodLabel(?PaymentMethod $method): ?string
    {
        return match ($method) {
            PaymentMethod::Cash => 'Cash',
            PaymentMethod::Eft => 'EFT',
            PaymentMethod::Card => 'Card',
            PaymentMethod::Other => 'Other',
            null => null,
        };
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
