<?php

namespace App\Domain\Reports\Services;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\Reports\Models\StatementFilterRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class StatementFilterReportService
{
    public const MAX_DETAILED_LINES = 400;

    /**
     * @return array<string, mixed>
     */
    public function run(
        StatementFilterReport $report,
        CarbonInterface $from,
        CarbonInterface $to,
        string $breakdown,
        string $view,
    ): array {
        $monthly = $breakdown === 'monthly';
        $detailed = $view === 'detailed';
        $rules = $report->rules()->get();
        $transactions = $this->transactions((int) $report->team_id, $from, $to);

        $filters = $rules->map(function (StatementFilterRule $rule) use ($transactions, $monthly, $detailed): array {
            $needle = mb_strtolower(trim($rule->match));
            $matched = $transactions->filter(
                fn (BankingTransaction $transaction): bool => $this->matches($transaction, $needle)
            )->values();

            $lines = $matched->map(fn (BankingTransaction $transaction): array => $this->present($transaction));
            $total = (int) $lines->sum('signed_cents');

            return [
                'id' => $rule->id,
                'alias' => $rule->alias,
                'match' => $rule->match,
                'count' => $lines->count(),
                'total_cents' => $total,
                'truncated' => $detailed && $lines->count() > self::MAX_DETAILED_LINES,
                'months' => $monthly ? $this->months($lines) : [],
                'transactions' => $detailed ? $lines->take(self::MAX_DETAILED_LINES)->all() : [],
            ];
        })->all();

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'presentation' => [
                'view' => $detailed ? 'detailed' : 'summary',
                'breakdown' => $monthly ? 'monthly' : 'period',
            ],
            'filters' => $filters,
        ];
    }

    /**
     * @return Collection<int, BankingTransaction>
     */
    private function transactions(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return BankingTransaction::query()
            ->where('team_id', $teamId)
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->with('account:id,name')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    private function matches(BankingTransaction $transaction, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        $haystack = mb_strtolower(trim($transaction->description.' '.($transaction->reference ?? '')));

        return str_contains($haystack, $needle);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankingTransaction $transaction): array
    {
        $cents = (int) round(((float) $transaction->amount) * 100);
        $outflow = $transaction->direction === TransactionDirection::Debit;

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->toDateString(),
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'account' => $transaction->account?->name,
            'direction' => $outflow ? 'out' : 'in',
            'signed_cents' => $outflow ? $cents : -$cents,
            'month_key' => $transaction->transaction_date?->format('Y-m'),
            'month_label' => $transaction->transaction_date?->format('F Y'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return list<array{key: string, label: string, count: int, total_cents: int}>
     */
    private function months(Collection $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $key = (string) ($line['month_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => (string) ($line['month_label'] ?? $key),
                    'count' => 0,
                    'total_cents' => 0,
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['total_cents'] += (int) $line['signed_cents'];
        }

        ksort($groups);

        return array_values($groups);
    }
}
