<?php

namespace App\Domain\Banking\Services;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingTransaction;
use Carbon\Carbon;

final class SuggestReconciliationCandidates
{
    public function __construct(
        private readonly BankingReconciliationTotals $totals,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(BankingTransaction $bankLine, int $limit = 25): array
    {
        $remainingBank = $this->totals->remainingBankCents($bankLine);
        if ($remainingBank < 1) {
            return [];
        }

        $date = $bankLine->transaction_date instanceof Carbon
            ? $bankLine->transaction_date
            : Carbon::parse((string) $bankLine->transaction_date);
        $from = $date->copy()->subDays(14)->toDateString();
        $to = $date->copy()->addDays(14)->toDateString();

        $alreadyMatchedIds = $bankLine->allocations()
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $transactions = Transaction::queryWithoutTeamScope()
            ->where('team_id', $bankLine->team_id)
            ->where('status', TransactionStatus::Posted)
            ->whereIn('type', [
                TransactionType::Payment,
                TransactionType::Expense,
                TransactionType::Transfer,
                TransactionType::JournalAdjustment,
            ])
            ->whereBetween('transaction_date', [$from, $to])
            ->when($alreadyMatchedIds !== [], fn ($query) => $query->whereNotIn('id', $alreadyMatchedIds))
            ->where(function ($query): void {
                $query->whereNull('reference')
                    ->orWhere('reference', 'not like', 'VOID-%');
            })
            ->with(['journalEntries', 'supplier', 'payments.invoice'])
            ->orderByDesc('transaction_date')
            ->limit(150)
            ->get();

        $scored = $transactions
            ->map(function (Transaction $transaction) use ($bankLine, $remainingBank): ?array {
                $remainingTransaction = $this->totals->remainingTransactionCents($transaction, $bankLine);
                if ($remainingTransaction < 1) {
                    return null;
                }

                $score = $this->score($bankLine, $transaction, $remainingBank, $remainingTransaction);
                $suggested = min($remainingBank, $remainingTransaction);

                return [
                    'transaction' => $transaction,
                    'score' => $score,
                    'remaining_cents' => $remainingTransaction,
                    'matchable_cents' => $this->totals->matchableTransactionCents($transaction, $bankLine),
                    'suggested_amount_cents' => $suggested,
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->all();
    }

    private function score(
        BankingTransaction $bankLine,
        Transaction $transaction,
        int $remainingBank,
        int $remainingTransaction,
    ): int {
        $score = 0;

        if ($remainingTransaction === $remainingBank) {
            $score += 100;
        } elseif ($remainingTransaction >= $remainingBank) {
            $score += 20;
        }

        $bankDate = $bankLine->transaction_date instanceof Carbon
            ? $bankLine->transaction_date->startOfDay()
            : Carbon::parse((string) $bankLine->transaction_date)->startOfDay();
        $txDate = $transaction->transaction_date instanceof Carbon
            ? $transaction->transaction_date->startOfDay()
            : Carbon::parse((string) $transaction->transaction_date)->startOfDay();
        $days = abs($bankDate->diffInDays($txDate));

        $score += match (true) {
            $days === 0 => 40,
            $days <= 3 => 25,
            $days <= 7 => 10,
            $days <= 14 => 5,
            default => 0,
        };

        $glAccountId = $bankLine->account?->gl_account_id;
        if ($glAccountId !== null) {
            $hitsGl = $transaction->journalEntries->contains(
                fn ($entry) => (int) $entry->account_id === (int) $glAccountId
            );
            if ($hitsGl) {
                $score += 30;
            }
        }

        $typeMatchesDirection = match ($bankLine->direction) {
            TransactionDirection::Credit => in_array($transaction->type, [TransactionType::Payment, TransactionType::Transfer], true),
            TransactionDirection::Debit => in_array($transaction->type, [TransactionType::Expense, TransactionType::Transfer, TransactionType::JournalAdjustment], true),
        };
        if ($typeMatchesDirection) {
            $score += 20;
        }

        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $transaction->description,
            $transaction->reference,
            $transaction->displayReference(),
            $transaction->displaySupplier(),
        ]))));
        $needles = array_values(array_filter([
            mb_strtolower(trim((string) $bankLine->description)),
            mb_strtolower(trim((string) $bankLine->reference)),
        ]));

        foreach ($needles as $needle) {
            if ($needle !== '' && $haystack !== '' && str_contains($haystack, $needle)) {
                $score += 15;
                break;
            }
            $tokens = preg_split('/\s+/', $needle) ?: [];
            foreach ($tokens as $token) {
                if (mb_strlen($token) >= 4 && str_contains($haystack, $token)) {
                    $score += 8;
                    break 2;
                }
            }
        }

        return $score;
    }
}
