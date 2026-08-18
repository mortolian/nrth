<?php

namespace App\Domain\Banking\Services;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use App\Domain\Banking\Support\BankingMoney;

final class BankingReconciliationTotals
{
    public function bankAmountCents(BankingTransaction $bankLine): int
    {
        return BankingMoney::toCents($bankLine->amount);
    }

    public function allocatedBankCents(BankingTransaction $bankLine, ?int $exceptAllocationId = null): int
    {
        $query = BankingTransactionAllocation::queryWithoutTeamScope()
            ->where('banking_transaction_id', $bankLine->id);

        if ($exceptAllocationId !== null) {
            $query->where('id', '!=', $exceptAllocationId);
        }

        return (int) $query->sum('amount_cents');
    }

    public function remainingBankCents(BankingTransaction $bankLine, ?int $exceptAllocationId = null): int
    {
        return max(0, $this->bankAmountCents($bankLine) - $this->allocatedBankCents($bankLine, $exceptAllocationId));
    }

    public function matchableTransactionCents(Transaction $transaction, BankingTransaction $bankLine): int
    {
        $bankLine->loadMissing('account');
        $glAccountId = $bankLine->account?->gl_account_id !== null
            ? (int) $bankLine->account->gl_account_id
            : null;

        $entries = $transaction->relationLoaded('journalEntries')
            ? $transaction->journalEntries
            : $transaction->journalEntries()->get();

        if ($glAccountId !== null) {
            $wantedType = $bankLine->direction === TransactionDirection::Credit
                ? EntryType::Debit
                : EntryType::Credit;

            $directional = 0;
            $anyOnGl = 0;
            foreach ($entries as $entry) {
                if ((int) $entry->account_id !== $glAccountId) {
                    continue;
                }
                $cents = (int) $entry->getRawOriginal('amount_cents');
                $anyOnGl += $cents;
                if ($entry->type === $wantedType) {
                    $directional += $cents;
                }
            }

            if ($directional > 0) {
                return $directional;
            }
            if ($anyOnGl > 0) {
                return $anyOnGl;
            }
        }

        $debits = 0;
        foreach ($entries as $entry) {
            if ($entry->type === EntryType::Debit) {
                $debits += (int) $entry->getRawOriginal('amount_cents');
            }
        }

        return $debits;
    }

    public function allocatedTransactionCents(Transaction $transaction, ?int $exceptAllocationId = null): int
    {
        $query = BankingTransactionAllocation::queryWithoutTeamScope()
            ->where('transaction_id', $transaction->id);

        if ($exceptAllocationId !== null) {
            $query->where('id', '!=', $exceptAllocationId);
        }

        return (int) $query->sum('amount_cents');
    }

    public function remainingTransactionCents(
        Transaction $transaction,
        BankingTransaction $bankLine,
        ?int $exceptAllocationId = null,
    ): int {
        if ($transaction->status !== TransactionStatus::Posted) {
            return 0;
        }

        return max(
            0,
            $this->matchableTransactionCents($transaction, $bankLine)
            - $this->allocatedTransactionCents($transaction, $exceptAllocationId)
        );
    }
}
