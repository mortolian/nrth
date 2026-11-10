<?php

namespace App\Domain\Reports\Services;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\Reports\Models\StatementFilterRule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

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
        $accountNames = BankingAccount::query()
            ->where('team_id', $report->team_id)
            ->pluck('name', 'id');

        /** @var array<int, array{count: int, total_cents: int, months: array<string, array{key: string, label: string, count: int, total_cents: int}>, transactions: list<array<string, mixed>>}> $buckets */
        $buckets = [];
        $needles = [];
        foreach ($rules as $rule) {
            $buckets[$rule->id] = [
                'count' => 0,
                'total_cents' => 0,
                'months' => [],
                'transactions' => [],
            ];
            $needles[$rule->id] = mb_strtolower(trim($rule->match));
        }

        foreach ($this->transactionQuery((int) $report->team_id, $from, $to)->cursor() as $transaction) {
            foreach ($rules as $rule) {
                if (! $this->matches($transaction, $needles[$rule->id])) {
                    continue;
                }

                $line = $this->present($transaction, $accountNames[$transaction->account_id] ?? null);
                $buckets[$rule->id]['count']++;
                $buckets[$rule->id]['total_cents'] += $line['signed_cents'];
                if ($monthly) {
                    $this->addMonth($buckets[$rule->id]['months'], $line);
                }
                if ($detailed && count($buckets[$rule->id]['transactions']) < self::MAX_DETAILED_LINES) {
                    $buckets[$rule->id]['transactions'][] = $line;
                }
            }
        }

        $filters = $rules->map(function (StatementFilterRule $rule) use ($buckets, $monthly, $detailed): array {
            $bucket = $buckets[$rule->id];
            $months = $bucket['months'];
            ksort($months);

            return [
                'id' => $rule->id,
                'alias' => $rule->alias,
                'match' => $rule->match,
                'count' => $bucket['count'],
                'total_cents' => $bucket['total_cents'],
                'truncated' => $detailed && $bucket['count'] > self::MAX_DETAILED_LINES,
                'months' => $monthly ? array_values($months) : [],
                'transactions' => $detailed ? $bucket['transactions'] : [],
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
     * @return Builder<BankingTransaction>
     */
    private function transactionQuery(int $teamId, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return BankingTransaction::query()
            ->select([
                'id',
                'account_id',
                'transaction_date',
                'description',
                'reference',
                'amount',
                'direction',
            ])
            ->where('team_id', $teamId)
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<=', $to->toDateString())
            ->orderBy('transaction_date')
            ->orderBy('id');
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
    private function present(BankingTransaction $transaction, ?string $accountName): array
    {
        $cents = (int) round(((float) $transaction->amount) * 100);
        $outflow = $transaction->direction === TransactionDirection::Debit;

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->toDateString(),
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'account' => $accountName,
            'direction' => $outflow ? 'out' : 'in',
            'signed_cents' => $outflow ? $cents : -$cents,
            'month_key' => $transaction->transaction_date?->format('Y-m'),
            'month_label' => $transaction->transaction_date?->format('F Y'),
        ];
    }

    /**
     * @param  array<string, array{key: string, label: string, count: int, total_cents: int}>  $groups
     * @param  array<string, mixed>  $line
     */
    private function addMonth(array &$groups, array $line): void
    {
        $key = (string) ($line['month_key'] ?? '');
        if ($key === '') {
            return;
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
}
