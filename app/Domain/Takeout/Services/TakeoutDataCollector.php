<?php

namespace App\Domain\Takeout\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Accounting\Services\ProfitLossReportService;
use App\Domain\Banking\Models\BankingStatementImport;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Contracting\Models\Contract;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Services\VATService;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

final class TakeoutDataCollector
{
    public function __construct(
        private readonly ProfitLossReportService $profitLossReportService,
        private readonly LedgerService $ledgerService,
        private readonly VATService $vatService,
    ) {}

    public function team(TakeoutRun $run): Team
    {
        return $run->team()->firstOrFail();
    }

    public function from(TakeoutRun $run): Carbon
    {
        return $run->from_date->copy()->startOfDay();
    }

    public function to(TakeoutRun $run): Carbon
    {
        return $run->to_date->copy()->endOfDay();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function invoices(TakeoutRun $run): Collection
    {
        return Invoice::queryWithoutTeamScope()
            ->with(['client:id,name'])
            ->where('team_id', $run->team_id)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereBetween('issue_date', [$run->from_date->toDateString(), $run->to_date->toDateString()])
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function expenses(TakeoutRun $run): Collection
    {
        return Transaction::queryWithoutTeamScope()
            ->with(['supplier:id,name', 'journalEntries.account', 'taxLines', 'media'])
            ->withCount('media')
            ->where('team_id', $run->team_id)
            ->where('type', TransactionType::Expense->value)
            ->where('status', TransactionStatus::Posted->value)
            ->whereBetween('transaction_date', [$run->from_date->toDateString(), $run->to_date->toDateString()])
            ->orderBy('transaction_date')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function payments(TakeoutRun $run): Collection
    {
        return Payment::queryWithoutTeamScope()
            ->with(['invoice:id,number,client_id', 'invoice.client:id,name'])
            ->where('team_id', $run->team_id)
            ->whereBetween('payment_date', [$run->from_date->toDateString(), $run->to_date->toDateString()])
            ->orderBy('payment_date')
            ->get();
    }

    /**
     * @return Collection<int, BankingTransaction>
     */
    public function bankTransactions(TakeoutRun $run): Collection
    {
        return BankingTransaction::queryWithoutTeamScope()
            ->with(['account:id,name'])
            ->where('team_id', $run->team_id)
            ->whereBetween('transaction_date', [$run->from_date->toDateString(), $run->to_date->toDateString()])
            ->orderBy('transaction_date')
            ->get();
    }

    /**
     * @return Collection<int, BankingStatementImport>
     */
    public function bankStatementImports(TakeoutRun $run): Collection
    {
        $importIds = BankingTransaction::queryWithoutTeamScope()
            ->where('team_id', $run->team_id)
            ->whereBetween('transaction_date', [$run->from_date->toDateString(), $run->to_date->toDateString()])
            ->whereNotNull('banking_statement_import_id')
            ->distinct()
            ->pluck('banking_statement_import_id');

        if ($importIds->isEmpty()) {
            return new Collection;
        }

        return BankingStatementImport::queryWithoutTeamScope()
            ->with(['account:id,name'])
            ->where('team_id', $run->team_id)
            ->whereIn('id', $importIds)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, TaxPeriod>
     */
    public function vatPeriods(TakeoutRun $run): Collection
    {
        if (! Schema::hasTable('tax_periods')) {
            return new Collection;
        }

        return TaxPeriod::queryWithoutTeamScope()
            ->with('vatReturn')
            ->where('team_id', $run->team_id)
            ->where('type', TaxPeriodType::VAT->value)
            ->whereDate('period_start', '<=', $run->to_date->toDateString())
            ->whereDate('period_end', '>=', $run->from_date->toDateString())
            ->orderBy('period_start')
            ->get();
    }

    /**
     * @return Collection<int, Contract>
     */
    public function contracts(TakeoutRun $run): Collection
    {
        $clientIds = $this->invoices($run)->pluck('client_id')->unique()->filter();

        return Contract::queryWithoutTeamScope()
            ->with(['client:id,name', 'media'])
            ->where('team_id', $run->team_id)
            ->where(function ($query) use ($run, $clientIds): void {
                $query->where(function ($overlap) use ($run): void {
                    $overlap->whereDate('start_date', '<=', $run->to_date->toDateString())
                        ->where(function ($end) use ($run): void {
                            $end->whereNull('end_date')
                                ->orWhereDate('end_date', '>=', $run->from_date->toDateString());
                        });
                });

                if ($clientIds->isNotEmpty()) {
                    $query->orWhereIn('client_id', $clientIds);
                }
            })
            ->orderBy('start_date')
            ->get();
    }

    /**
     * @return array{income: array<int, array<string, mixed>>, expenses: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function profitAndLoss(TakeoutRun $run): array
    {
        return $this->profitLossReportService->forPeriod(
            $run->team_id,
            $this->from($run),
            $this->to($run),
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function trialBalance(TakeoutRun $run): \Illuminate\Support\Collection
    {
        return $this->ledgerService->trialBalance($this->team($run), $this->to($run));
    }

    public function expenseTotalCents(Transaction $transaction): int
    {
        return (int) $transaction->journalEntries
            ->filter(fn ($line) => $line->account?->type === AccountType::Expense && $line->type === EntryType::Debit)
            ->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
    }

    public function expenseVatCents(Transaction $transaction): int
    {
        return (int) $transaction->taxLines->sum(fn ($line) => (int) $line->getRawOriginal('tax_amount_cents'));
    }

    /**
     * @return array{output_vat_cents: int, input_vat_cents: int, net_vat_cents: int}
     */
    public function vatPeriodAmounts(TaxPeriod $period): array
    {
        if ($period->relationLoaded('vatReturn') && $period->vatReturn !== null) {
            return [
                'output_vat_cents' => (int) $period->vatReturn->output_vat_cents,
                'input_vat_cents' => (int) $period->vatReturn->input_vat_cents,
                'net_vat_cents' => (int) $period->vatReturn->net_vat_cents,
            ];
        }

        $team = $period->team ?? $period->team()->firstOrFail();
        $summary = $this->vatService->getVATSummary($team, $period);

        return [
            'output_vat_cents' => $summary->outputVAT->getMinorAmount()->toInt(),
            'input_vat_cents' => $summary->inputVAT->getMinorAmount()->toInt(),
            'net_vat_cents' => $summary->netVAT->getMinorAmount()->toInt(),
        ];
    }
}
