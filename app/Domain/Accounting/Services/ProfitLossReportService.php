<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\JournalEntry;
use Carbon\Carbon;

final class ProfitLossReportService
{
    /**
     * @return array{income: array<int, array<string, mixed>>, expenses: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function forPeriod(int $teamId, Carbon $from, Carbon $to): array
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
}
