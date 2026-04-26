<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    public function profitLoss(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $preset = (string) $request->string('preset')->toString() ?: 'this_month';
        $compare = (string) $request->string('compare')->toString() ?: 'none';

        [$from, $to] = $this->resolvePeriod(
            $preset,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null
        );

        $report = $this->profitLossData((int) $team->id, $from, $to);
        $comparison = null;
        if ($compare === 'previous_period') {
            $days = max(1, $from->diffInDays($to) + 1);
            $compTo = $from->copy()->subDay();
            $compFrom = $compTo->copy()->subDays($days - 1);
            $comparison = $this->profitLossData((int) $team->id, $compFrom, $compTo);
        } elseif ($compare === 'same_period_last_year') {
            $comparison = $this->profitLossData((int) $team->id, $from->copy()->subYear(), $to->copy()->subYear());
        }

        return Inertia::render('Reports/ProfitLoss', [
            'report' => $report,
            'comparison' => $comparison,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'preset' => $preset,
            ],
            'compare' => $compare,
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $asOf = $request->string('as_of')->toString()
            ? Carbon::parse($request->string('as_of')->toString())->endOfDay()
            : now()->endOfDay();

        $trial = $this->ledgerService->trialBalance($team, $asOf);
        $accounts = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($trial as $row) {
            $account = $accounts[$row->account->id] ?? null;
            if ($account === null) {
                continue;
            }
            $balance = $this->ledgerService->getBalance($account, $asOf)->getMinorAmount()->toInt();
            if ($balance === 0) {
                continue;
            }

            $line = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $balance,
            ];

            if ($account->type === AccountType::Asset) {
                $assets[] = $line;
            } elseif ($account->type === AccountType::Liability) {
                $liabilities[] = $line;
            } elseif ($account->type === AccountType::Equity) {
                $equity[] = $line;
            }
        }

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount'));
        $totalLiabilitiesEquity = $totalLiabilities + $totalEquity;

        return Inertia::render('Reports/BalanceSheet', [
            'report' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'totals' => [
                    'assets' => $totalAssets,
                    'liabilities' => $totalLiabilities,
                    'equity' => $totalEquity,
                    'liabilities_plus_equity' => $totalLiabilitiesEquity,
                ],
                'is_balanced' => $totalAssets === $totalLiabilitiesEquity,
            ],
            'as_of' => $asOf->toDateString(),
        ]);
    }

    public function cashFlow(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $teamId = (int) $team->id;
        $preset = (string) $request->string('preset')->toString() ?: 'this_month';

        [$from, $to] = $this->resolvePeriod(
            $preset,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null
        );

        $pl = $this->profitLossData($teamId, $from, $to);
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
     * @return array{income: array<int, array<string, mixed>>, expenses: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    private function profitLossData(int $teamId, Carbon $from, Carbon $to): array
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
                ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value]))
            ->with('account:id,code,name,type')
            ->get();

        $income = $entries
            ->filter(fn (JournalEntry $entry) => $entry->account?->type === AccountType::Income)
            ->groupBy('account_id')
            ->map(function ($rows): array {
                $account = $rows->first()->account;
                $credit = (int) $rows->where('type', EntryType::Credit)->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $debit = (int) $rows->where('type', EntryType::Debit)->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));

                return [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'amount' => max(0, $credit - $debit),
                ];
            })
            ->values()
            ->sortBy('code')
            ->values()
            ->all();

        $expenses = $entries
            ->filter(fn (JournalEntry $entry) => $entry->account?->type === AccountType::Expense)
            ->groupBy('account_id')
            ->map(function ($rows): array {
                $account = $rows->first()->account;
                $debit = (int) $rows->where('type', EntryType::Debit)->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $credit = (int) $rows->where('type', EntryType::Credit)->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));

                return [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'amount' => max(0, $debit - $credit),
                ];
            })
            ->values()
            ->sortBy('code')
            ->values()
            ->all();

        $totalIncome = (int) array_sum(array_column($income, 'amount'));
        $totalExpenses = (int) array_sum(array_column($expenses, 'amount'));

        return [
            'income' => $income,
            'expenses' => $expenses,
            'totals' => [
                'income' => $totalIncome,
                'expenses' => $totalExpenses,
                'net_profit' => $totalIncome - $totalExpenses,
            ],
        ];
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
                ->where('status', TransactionStatus::Posted)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->sum('amount_cents');

        $credit = (int) JournalEntry::query()
            ->where('account_id', $accountId)
            ->where('type', EntryType::Credit)
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('status', TransactionStatus::Posted)
                ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]))
            ->sum('amount_cents');

        return max(0, $debit - $credit);
    }
}
