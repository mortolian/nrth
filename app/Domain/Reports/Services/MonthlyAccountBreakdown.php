<?php

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class MonthlyAccountBreakdown
{
    /**
     * Twelve financial-year months, each with the accounts that moved that month.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @param  callable(JournalEntry): int  $signedAmount
     * @return list<array{key: string, label: string, accounts: list<array{account_id: int, code: string, name: string, amount: int}>, total: int}>
     */
    public static function build(Collection $entries, CarbonInterface $from, CarbonInterface $to, callable $signedAmount): array
    {
        $buckets = [];
        $cursor = Carbon::parse($from->toDateString())->startOfMonth();
        $last = Carbon::parse($to->toDateString())->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = [
                'key' => $key,
                'label' => $cursor->format('F Y'),
                'accounts' => [],
                'total' => 0,
            ];
            $cursor = $cursor->copy()->addMonth();
        }

        /** @var array<string, array<int, array{account_id: int, code: string, name: string, amount: int}>> $grouped */
        $grouped = [];

        foreach ($entries as $entry) {
            $date = $entry->transaction?->transaction_date;
            if ($date === null) {
                continue;
            }

            $key = $date->format('Y-m');
            if (! isset($buckets[$key]) || $entry->account === null) {
                continue;
            }

            $accountId = (int) $entry->account_id;
            if (! isset($grouped[$key][$accountId])) {
                $grouped[$key][$accountId] = [
                    'account_id' => $accountId,
                    'code' => (string) $entry->account->code,
                    'name' => (string) $entry->account->name,
                    'amount' => 0,
                ];
            }

            $grouped[$key][$accountId]['amount'] += (int) $signedAmount($entry);
        }

        $months = [];
        foreach ($buckets as $key => $bucket) {
            $accounts = collect($grouped[$key] ?? [])
                ->filter(fn (array $row): bool => $row['amount'] !== 0)
                ->sortBy('code')
                ->values()
                ->all();
            $bucket['accounts'] = $accounts;
            $bucket['total'] = (int) array_sum(array_column($accounts, 'amount'));
            $months[] = $bucket;
        }

        return $months;
    }
}
