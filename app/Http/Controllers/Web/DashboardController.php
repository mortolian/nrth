<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\Services\VATService;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly VATService $vatService,
    ) {}

    public function __invoke(): Response
    {
        /** @var Team $team */
        $team = auth()->user()->currentTeam;
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $monthStart->copy()->subMonth()->endOfMonth();

        $currentRevenue = $this->sumByAccountType($team->id, AccountType::Income, $monthStart, $monthEnd);
        $lastRevenue = $this->sumByAccountType($team->id, AccountType::Income, $lastMonthStart, $lastMonthEnd);
        $currentExpenses = $this->sumByAccountType($team->id, AccountType::Expense, $monthStart, $monthEnd);
        $lastExpenses = $this->sumByAccountType($team->id, AccountType::Expense, $lastMonthStart, $lastMonthEnd);

        $netProfitCurrent = $currentRevenue - $currentExpenses;
        $netProfitLast = $lastRevenue - $lastExpenses;

        $outstandingInvoices = $this->getOutstandingInvoices($team, $now);
        $outstandingRows = $outstandingInvoices->items();
        $outstandingTotal = array_sum(array_column($outstandingRows, 'amount'));
        $vatEnabled = $team->chargesVat();

        $vatDueCurrent = 0;
        $vatOutputCurrent = 0;
        $vatInputCurrent = 0;
        $vatTrend = null;
        if ($vatEnabled) {
            $vatDueCurrent = $this->vatService
                ->calculateNetVAT($team, $monthStart, $monthEnd)
                ->getMinorAmount()
                ->toInt();
            $vatOutputCurrent = $this->vatService->calculateOutputVAT($team, $monthStart, $monthEnd)->getMinorAmount()->toInt();
            $vatInputCurrent = $this->vatService->calculateInputVAT($team, $monthStart, $monthEnd)->getMinorAmount()->toInt();
            $vatTrend = $this->trend(
                $vatDueCurrent,
                $this->vatService->calculateNetVAT($team, $lastMonthStart, $lastMonthEnd)->getMinorAmount()->toInt()
            );
        }

        return Inertia::render('Dashboard', [
            'kpis' => [
                'revenue_mtd' => $this->kpiPayload($currentRevenue, $this->trend($currentRevenue, $lastRevenue)),
                'outstanding_invoices' => $this->kpiPayload($outstandingTotal, $this->trend($outstandingTotal, $this->getOutstandingInvoicesTotal($team, $lastMonthEnd))),
                'vat_liability' => $this->kpiPayload($vatDueCurrent, $vatTrend),
                'net_profit_mtd' => $this->kpiPayload($netProfitCurrent, $this->trend($netProfitCurrent, $netProfitLast)),
            ],
            'vat_enabled' => $vatEnabled,
            'revenue_chart' => $this->revenueChart($team),
            'outstanding_invoices' => $outstandingInvoices,
            'recent_transactions' => $this->recentTransactions($team),
            'budget_progress' => collect($this->budgetProgress($team, $monthStart, $monthEnd))
                ->map(fn (array $item) => [
                    'category' => $item['category'],
                    'allocated' => $item['allocated_cents'],
                    'spent' => $item['spent_cents'],
                    'percentage' => $item['progress_percent'],
                ])
                ->all(),
            'vat_summary' => [
                'current_period' => $monthStart->format('M Y'),
                'output_vat' => $vatOutputCurrent,
                'input_vat' => $vatInputCurrent,
                'net_vat' => $vatDueCurrent,
                'due_date' => $monthEnd->copy()->addDays(25)->toDateString(),
            ],
        ]);
    }

    private function sumByAccountType(int $teamId, AccountType $type, Carbon $from, Carbon $to): int
    {
        $entries = JournalEntry::query()
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('status', TransactionStatus::Posted->value)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->whereHas('account', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('type', $type->value))
            ->get();

        $credit = (int) $entries->where('type', EntryType::Credit)->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
        $debit = (int) $entries->where('type', EntryType::Debit)->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));

        return $type === AccountType::Income ? max(0, $credit - $debit) : max(0, $debit - $credit);
    }

    /**
     * @return array{labels: array<int, string>, revenue_cents: array<int, int>, expense_cents: array<int, int>}
     */
    private function revenueChart(Team $team): array
    {
        $rows = [];

        for ($i = 5; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $rows[] = [
                'month' => $start->format('M Y'),
                'revenue' => $this->sumByAccountType($team->id, AccountType::Income, $start, $end),
                'expenses' => $this->sumByAccountType($team->id, AccountType::Expense, $start, $end),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOutstandingInvoices(Team $team, Carbon $asOf): LengthAwarePaginator
    {
        if (! Schema::hasTable('invoices')) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 5);
        }

        return Invoice::queryWithoutTeamScope()
            ->with('client:id,name')
            ->withCount('payments')
            ->where('team_id', $team->id)
            ->whereNotIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Void->value])
            ->orderBy('due_date')
            ->paginate(5)
            ->through(function (Invoice $invoice) use ($asOf): array {
                $total = (int) $invoice->getRawOriginal('total_cents');
                $paid = (int) $invoice->getRawOriginal('amount_paid_cents');
                $due = max(0, $total - $paid);
                $dueDate = Carbon::parse($invoice->due_date);

                return [
                    'id' => $invoice->id,
                    'client' => $invoice->client?->name ?? 'Unknown',
                    'number' => $invoice->number,
                    'amount' => $due,
                    'due_date' => $dueDate->toDateString(),
                    'days_overdue' => $dueDate->isPast() ? abs($dueDate->diffInDays($asOf)) : 0,
                    'status' => $invoice->status->value,
                    'currency' => Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
                    'can_delete' => (int) $invoice->payments_count === 0,
                ];
            });
    }

    private function getOutstandingInvoicesTotal(Team $team, Carbon $asOfDate): int
    {
        return array_sum(array_column($this->getOutstandingInvoices($team, $asOfDate)->items(), 'amount'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentTransactions(Team $team): array
    {
        return Transaction::queryWithoutTeamScope()
            ->with(['journalEntries.account:id,name'])
            ->where('team_id', $team->id)
            ->orderByDesc('transaction_date')
            ->limit(10)
            ->get()
            ->map(function (Transaction $transaction): array {
                $line = $transaction->journalEntries->first();
                $amount = $line ? (int) $line->getRawOriginal('amount_cents') : 0;

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'description' => $transaction->description ?: (string) $transaction->type->value,
                    'account' => $line?->account?->name ?? 'N/A',
                    'amount_cents' => $amount,
                    'type' => $transaction->type->value,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function budgetProgress(Team $team, Carbon $from, Carbon $to): array
    {
        $entries = JournalEntry::query()
            ->with('account:id,name')
            ->where('type', EntryType::Debit)
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('status', TransactionStatus::Posted->value)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->whereHas('account', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('type', AccountType::Expense->value))
            ->get();

        return $entries
            ->groupBy(fn (JournalEntry $entry) => $entry->account?->name ?? 'Uncategorised')
            ->map(function ($group, $name): array {
                $spent = (int) $group->sum(fn (JournalEntry $entry) => (int) $entry->getRawOriginal('amount_cents'));
                $allocated = (int) max(100_00, round($spent * 1.2));

                return [
                    'category' => $name,
                    'spent_cents' => $spent,
                    'allocated_cents' => $allocated,
                    'progress_percent' => $allocated > 0 ? min(100, (int) round(($spent / $allocated) * 100)) : 0,
                ];
            })
            ->values()
            ->all();
    }

    private function trend(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * @return array{amount: int, trend_percentage: float|null, trend_direction: string}
     */
    private function kpiPayload(int $amount, ?float $trend): array
    {
        return [
            'amount' => $amount,
            'trend_percentage' => $trend,
            'trend_direction' => $trend === null ? 'neutral' : ($trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'neutral')),
        ];
    }
}
