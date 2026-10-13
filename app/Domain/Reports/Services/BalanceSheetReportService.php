<?php

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\LedgerService;
use App\Models\Team;
use Carbon\Carbon;

final class BalanceSheetReportService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * @return array{
     *     assets: array<int, array{account_id: int, code: string, name: string, amount: int}>,
     *     liabilities: array<int, array{account_id: int, code: string, name: string, amount: int}>,
     *     equity: array<int, array{account_id: int, code: string, name: string, amount: int}>,
     *     totals: array{assets: int, liabilities: int, equity: int, liabilities_plus_equity: int},
     *     is_balanced: bool
     * }
     */
    public function asOf(Team $team, Carbon $asOf): array
    {
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

        return [
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
        ];
    }
}
