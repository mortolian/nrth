<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Accounting\Services\ProfitLossReportService;
use App\Domain\Reports\Services\BalanceSheetPdfService;
use App\Domain\Reports\Services\BalanceSheetReportService;
use App\Domain\Reports\Services\ProfitLossPdfService;
use App\Http\Controllers\Controller;
use App\Support\StickyFilters;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReportsController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly ProfitLossReportService $profitLossReportService,
        private readonly ProfitLossPdfService $profitLossPdfService,
        private readonly BalanceSheetReportService $balanceSheetReportService,
        private readonly BalanceSheetPdfService $balanceSheetPdfService,
    ) {}

    public function profitLoss(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.profit_loss', [
            'preset' => null,
            'compare' => null,
            'from' => null,
            'to' => null,
        ]);
        $bundle = $this->profitLossBundle($request);

        return Inertia::render('Reports/ProfitLoss', [
            'report' => $bundle['report'],
            'comparison' => $bundle['comparison'],
            'period' => $bundle['period'],
            'compare' => $bundle['compare'],
        ]);
    }

    public function profitLossPdf(Request $request): SymfonyResponse|RedirectResponse
    {
        $this->authorizeTeam('reports.export', $request);
        abort_if($request->user()->currentTeam === null, 403);

        try {
            return $this->profitLossPdfService->download($request->user()->currentTeam, $this->profitLossBundle($request));
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: __('Could not export PDF.'));
        }
    }

    public function balanceSheet(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.balance_sheet', [
            'as_of' => null,
        ]);
        $team = $request->user()->currentTeam;
        $asOf = $this->balanceSheetAsOf($request);

        return Inertia::render('Reports/BalanceSheet', [
            'report' => $this->balanceSheetReportService->asOf($team, $asOf),
            'as_of' => $asOf->toDateString(),
        ]);
    }

    public function balanceSheetPdf(Request $request): SymfonyResponse|RedirectResponse
    {
        $this->authorizeTeam('reports.export', $request);
        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        try {
            return $this->balanceSheetPdfService->download($team, $this->balanceSheetAsOf($request));
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?: __('Could not export PDF.'));
        }
    }

    public function cashFlow(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.cash_flow', [
            'preset' => null,
            'from' => null,
            'to' => null,
        ]);
        $team = $request->user()->currentTeam;
        $teamId = (int) $team->id;
        $preset = (string) $request->string('preset')->toString() ?: 'this_month';

        [$from, $to] = $this->resolvePeriod(
            $preset,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null
        );

        $pl = $this->profitLossReportService->forPeriod($teamId, $from, $to);
        $netProfit = $pl['totals']['net_profit'];

        $accounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get();
        $byId = $accounts->keyBy('id');

        $deprAccount = $accounts->firstWhere('code', '5800');
        $depreciation = $deprAccount
            ? $this->expenseAccountNetDebitCents($teamId, $deprAccount->id, $from, $to)
            : 0;

        $arAccount = $accounts->firstWhere('code', '1100');
        $apAccount = $accounts->firstWhere('code', '2000');
        $dayBefore = $from->copy()->subDay()->endOfDay();

        $arStart = $arAccount ? $this->ledgerService->getBalance($arAccount, $dayBefore)->getMinorAmount()->toInt() : 0;
        $arEnd = $arAccount ? $this->ledgerService->getBalance($arAccount, $to)->getMinorAmount()->toInt() : 0;
        $arDelta = $arEnd - $arStart;
        $arCashEffect = -$arDelta;

        $apStart = $apAccount ? $this->ledgerService->getBalance($apAccount, $dayBefore)->getMinorAmount()->toInt() : 0;
        $apEnd = $apAccount ? $this->ledgerService->getBalance($apAccount, $to)->getMinorAmount()->toInt() : 0;
        $apDelta = $apEnd - $apStart;
        $apCashEffect = $apDelta;

        $operatingLines = [
            ['key' => 'net_profit', 'label' => 'Net Profit', 'amount' => $netProfit],
            ['key' => 'depreciation', 'label' => 'Add: Depreciation', 'amount' => $depreciation],
            ['key' => 'wc_heading', 'label' => 'Changes in working capital:', 'amount' => null, 'is_heading' => true],
            [
                'key' => 'receivables',
                'label' => $arDelta >= 0 ? 'Increase in Receivables' : 'Decrease in Receivables',
                'amount' => $arCashEffect,
            ],
            [
                'key' => 'payables',
                'label' => $apDelta >= 0 ? 'Increase in Payables' : 'Decrease in Payables',
                'amount' => $apCashEffect,
            ],
        ];

        $operatingSubtotal = $netProfit + $depreciation + $arCashEffect + $apCashEffect;

        $cashIds = $this->cashAccountIds($accounts);
        $cashIdSet = array_fill_keys($cashIds, true);
        $investIds = $this->investingAssetAccountIds($accounts, $cashIdSet);
        $investIdSet = array_fill_keys($investIds, true);

        $investingLines = [];
        $investingNet = 0;
        $financingLines = [];
        $financingNet = 0;

        $transactions = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('status', TransactionStatus::Posted)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->with(['journalEntries.account'])
            ->get();

        foreach ($transactions as $transaction) {
            $entries = $transaction->journalEntries;
            if ($entries->isEmpty()) {
                continue;
            }

            $bankNet = $this->netBankCashFlowCents($entries, $cashIdSet);
            if ($bankNet === 0) {
                continue;
            }

            $hasCash = $entries->contains(fn (JournalEntry $e): bool => isset($cashIdSet[$e->account_id]));
            if (! $hasCash) {
                continue;
            }

            $hasEquity = $entries->contains(fn (JournalEntry $e): bool => $e->account?->type === AccountType::Equity);
            $hasInvest = $entries->contains(fn (JournalEntry $e): bool => isset($investIdSet[$e->account_id]));
            $hasIncome = $entries->contains(fn (JournalEntry $e): bool => $e->account?->type === AccountType::Income);
            $hasExpense = $entries->contains(fn (JournalEntry $e): bool => $e->account?->type === AccountType::Expense);

            if ($hasEquity && ! $hasIncome && ! $hasExpense) {
                $financingNet += $bankNet;
                if ($bankNet < 0) {
                    $financingLines['Owner Drawings'] = ($financingLines['Owner Drawings'] ?? 0) + $bankNet;
                } else {
                    $financingLines['Owner Contributions'] = ($financingLines['Owner Contributions'] ?? 0) + $bankNet;
                }

                continue;
            }

            if ($hasInvest && ! $hasIncome && ! $hasExpense && ! $hasEquity) {
                $investingNet += $bankNet;
                $investEntry = $entries->first(
                    fn (JournalEntry $e): bool => isset($investIdSet[$e->account_id]) && $e->type === EntryType::Debit
                );
                $name = $investEntry?->account?->name ?? 'assets';
                $label = $bankNet < 0 ? 'Purchase of '.$name : 'Proceeds from sale of '.$name;
                $investingLines[$label] = ($investingLines[$label] ?? 0) + $bankNet;
            }
        }

        $investingLinesList = collect($investingLines)
            ->map(fn (int $cents, string $label): array => ['label' => $label, 'amount' => $cents])
            ->values()
            ->all();

        if ($investingLinesList === []) {
            $investingLinesList = [['label' => 'No investing cash flows in period', 'amount' => 0, 'is_placeholder' => true]];
        }

        $financingLinesList = collect($financingLines)
            ->map(fn (int $cents, string $label): array => ['label' => $label, 'amount' => $cents])
            ->values()
            ->all();

        if ($financingLinesList === []) {
            $financingLinesList = [['label' => 'No financing cash flows in period', 'amount' => 0, 'is_placeholder' => true]];
        }

        $netChange = $operatingSubtotal + $investingNet + $financingNet;

        $openingCash = 0;
        $closingCash = 0;
        foreach ($cashIds as $cashAccountId) {
            $cashAccount = $byId[$cashAccountId] ?? null;
            if ($cashAccount === null) {
                continue;
            }
            $openingCash += $this->ledgerService->getBalance($cashAccount, $dayBefore)->getMinorAmount()->toInt();
            $closingCash += $this->ledgerService->getBalance($cashAccount, $to)->getMinorAmount()->toInt();
        }

        $impliedClosing = $openingCash + $netChange;
        $reconciliationDiff = $closingCash - $impliedClosing;

        return Inertia::render('Reports/CashFlow', [
            'report' => [
                'operating' => [
                    'lines' => $operatingLines,
                    'subtotal' => $operatingSubtotal,
                ],
                'investing' => [
                    'lines' => $investingLinesList,
                    'subtotal' => $investingNet,
                ],
                'financing' => [
                    'lines' => $financingLinesList,
                    'subtotal' => $financingNet,
                ],
                'summary' => [
                    'net_change' => $netChange,
                    'opening_cash' => $openingCash,
                    'closing_cash' => $closingCash,
                    'reconciliation_difference' => $reconciliationDiff,
                ],
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'preset' => $preset,
            ],
        ]);
    }

    public function trialBalance(Request $request): Response
    {
        $this->authorizeTeam('reports.view', $request);
        StickyFilters::remember($request, 'reports.trial_balance', [
            'as_of' => null,
        ]);
        $team = $request->user()->currentTeam;
        $asOf = $request->string('as_of')->toString()
            ? Carbon::parse($request->string('as_of')->toString())->endOfDay()
            : now()->endOfDay();

        $trial = $this->ledgerService->trialBalance($team, $asOf);
        $groups = [
            AccountType::Asset->value => [],
            AccountType::Liability->value => [],
            AccountType::Equity->value => [],
            AccountType::Income->value => [],
            AccountType::Expense->value => [],
        ];
        $subtotals = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($trial as $row) {
            $account = $row->account;
            $debitRaw = $row->debit_total->getMinorAmount()->toInt();
            $creditRaw = $row->credit_total->getMinorAmount()->toInt();

            $debit = 0;
            $credit = 0;
            if ($account->type->isDebit()) {
                $net = $debitRaw - $creditRaw;
                $debit = max(0, $net);
                $credit = max(0, -$net);
            } else {
                $net = $creditRaw - $debitRaw;
                $credit = max(0, $net);
                $debit = max(0, -$net);
            }

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $groups[$account->type->value][] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'debit' => $debit,
                'credit' => $credit,
            ];

            $subtotals[$account->type->value]['debit'] = ($subtotals[$account->type->value]['debit'] ?? 0) + $debit;
            $subtotals[$account->type->value]['credit'] = ($subtotals[$account->type->value]['credit'] ?? 0) + $credit;
            $totalDebits += $debit;
            $totalCredits += $credit;
        }

        return Inertia::render('Reports/TrialBalance', [
            'report' => [
                'groups' => $groups,
                'subtotals' => $subtotals,
                'totals' => [
                    'debits' => $totalDebits,
                    'credits' => $totalCredits,
                    'difference' => abs($totalDebits - $totalCredits),
                    'is_balanced' => $totalDebits === $totalCredits,
                ],
            ],
            'as_of' => $asOf->toDateString(),
        ]);
    }

    /**
     * @return array{
     *     report: array<string, mixed>,
     *     comparison: array<string, mixed>|null,
     *     period: array{from: string, to: string, preset: string},
     *     compare: string,
     *     comparison_period: array{from: string, to: string}|null
     * }
     */
    private function profitLossBundle(Request $request): array
    {
        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $preset = (string) $request->string('preset')->toString() ?: 'this_month';
        $compare = (string) $request->string('compare')->toString() ?: 'none';

        [$from, $to] = $this->resolvePeriod(
            $preset,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null
        );

        $report = $this->profitLossReportService->forPeriod((int) $team->id, $from, $to);
        $comparison = null;
        $comparisonPeriod = null;
        if ($compare === 'previous_period') {
            $days = max(1, $from->diffInDays($to) + 1);
            $compTo = $from->copy()->subDay();
            $compFrom = $compTo->copy()->subDays($days - 1);
            $comparison = $this->profitLossReportService->forPeriod((int) $team->id, $compFrom, $compTo);
            $comparisonPeriod = [
                'from' => $compFrom->toDateString(),
                'to' => $compTo->toDateString(),
            ];
        } elseif ($compare === 'same_period_last_year') {
            $compFrom = $from->copy()->subYear();
            $compTo = $to->copy()->subYear();
            $comparison = $this->profitLossReportService->forPeriod((int) $team->id, $compFrom, $compTo);
            $comparisonPeriod = [
                'from' => $compFrom->toDateString(),
                'to' => $compTo->toDateString(),
            ];
        }

        return [
            'report' => $report,
            'comparison' => $comparison,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'preset' => $preset,
            ],
            'compare' => $compare,
            'comparison_period' => $comparisonPeriod,
        ];
    }

    private function balanceSheetAsOf(Request $request): Carbon
    {
        return $request->string('as_of')->toString()
            ? Carbon::parse($request->string('as_of')->toString())->endOfDay()
            : now()->endOfDay();
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function resolvePeriod(string $preset, ?string $from, ?string $to): array
    {
        $now = now();

        return match ($preset) {
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'this_tax_year' => $this->taxYearWindow($now, false),
            'last_tax_year' => $this->taxYearWindow($now, true),
            'custom' => [
                $from ? Carbon::parse($from)->startOfDay() : $now->copy()->startOfMonth(),
                $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfMonth(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function taxYearWindow(Carbon $base, bool $previous): array
    {
        $start = $base->month >= 3
            ? Carbon::create($base->year, 3, 1)->startOfDay()
            : Carbon::create($base->year - 1, 3, 1)->startOfDay();
        if ($previous) {
            $start = $start->copy()->subYear();
        }
        $end = $start->copy()->addYear()->subDay()->endOfDay();

        return [$start, $end];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return list<int>
     */
    private function cashAccountIds(Collection $accounts): array
    {
        $byId = $accounts->keyBy('id');
        $ids = [];
        foreach ($accounts as $account) {
            if ($this->accountIsBankOrCashBucket($account, $byId)) {
                $ids[] = $account->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, bool>  $cashIdSet
     * @return list<int>
     */
    private function investingAssetAccountIds(Collection $accounts, array $cashIdSet): array
    {
        $ids = [];
        foreach ($accounts as $account) {
            if ($account->type !== AccountType::Asset) {
                continue;
            }
            if (isset($cashIdSet[$account->id])) {
                continue;
            }
            if (in_array($account->code, ['1100', '1200'], true)) {
                continue;
            }
            $ids[] = $account->id;
        }

        return $ids;
    }

    /**
     * @param  Collection<int, Account>  $byId
     */
    private function accountIsBankOrCashBucket(Account $account, Collection $byId): bool
    {
        // 1000 is a summary header; leaf bank/cash accounts are 1010 / 1020 (or custom children of 1000).
        if ($account->code === '1010' || $account->code === '1020') {
            return true;
        }

        $walk = $account;
        while ($walk->parent_id) {
            $parent = $byId[$walk->parent_id] ?? null;
            if ($parent === null) {
                break;
            }
            if ($parent->code === '1000') {
                return true;
            }
            $walk = $parent;
        }

        return false;
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     * @param  array<int, bool>  $cashIdSet
     */
    private function netBankCashFlowCents(Collection $entries, array $cashIdSet): int
    {
        $net = 0;
        foreach ($entries as $entry) {
            if (! isset($cashIdSet[$entry->account_id])) {
                continue;
            }
            $cents = (int) $entry->getRawOriginal('amount_cents');
            $net += $entry->type === EntryType::Debit ? $cents : -$cents;
        }

        return $net;
    }

    private function expenseAccountNetDebitCents(int $teamId, int $accountId, Carbon $from, Carbon $to): int
    {
        $debit = (int) JournalEntry::query()
            ->where('account_id', $accountId)
            ->where('type', EntryType::Debit)
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->forPeriodReporting()
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->sum('amount_cents');

        $credit = (int) JournalEntry::query()
            ->where('account_id', $accountId)
            ->where('type', EntryType::Credit)
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->forPeriodReporting()
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->sum('amount_cents');

        return max(0, $debit - $credit);
    }
}
