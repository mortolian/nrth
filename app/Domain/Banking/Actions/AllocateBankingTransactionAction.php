<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use App\Domain\Banking\Services\BankingReconciliationTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AllocateBankingTransactionAction
{
    public function __construct(
        private readonly BankingReconciliationTotals $totals,
        private readonly RecalculateBankingReconciliationStatus $recalculate,
    ) {}

    public function execute(
        BankingTransaction $bankLine,
        Transaction $transaction,
        int $amountCents,
        int $teamId,
        ?int $userId,
        ?string $note = null,
    ): BankingTransactionAllocation {
        if ((int) $bankLine->team_id !== $teamId || (int) $transaction->team_id !== $teamId) {
            throw ValidationException::withMessages([
                'transaction_id' => __('That record does not belong to this business.'),
            ]);
        }

        if ($transaction->status !== TransactionStatus::Posted) {
            throw ValidationException::withMessages([
                'transaction_id' => __('Only posted accounting transactions can be matched.'),
            ]);
        }

        if ($amountCents < 1) {
            throw ValidationException::withMessages([
                'amount_cents' => __('Allocation amount must be greater than zero.'),
            ]);
        }

        return DB::transaction(function () use ($bankLine, $transaction, $amountCents, $teamId, $userId, $note): BankingTransactionAllocation {
            $existing = BankingTransactionAllocation::queryWithoutTeamScope()
                ->where('banking_transaction_id', $bankLine->id)
                ->where('transaction_id', $transaction->id)
                ->first();

            $exceptId = $existing?->id;
            $remainingBank = $this->totals->remainingBankCents($bankLine, $exceptId);
            $remainingTransaction = $this->totals->remainingTransactionCents($transaction, $bankLine, $exceptId);

            if ($amountCents > $remainingBank) {
                throw ValidationException::withMessages([
                    'amount_cents' => __('Allocation cannot exceed the remaining bank amount.'),
                ]);
            }

            if ($amountCents > $remainingTransaction) {
                throw ValidationException::withMessages([
                    'amount_cents' => __('Allocation cannot exceed the remaining amount on that accounting transaction.'),
                ]);
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'amount_cents' => $amountCents,
                    'note' => $note,
                ])->save();
                $allocation = $existing;
            } else {
                $allocation = BankingTransactionAllocation::queryWithoutTeamScope()->create([
                    'team_id' => $teamId,
                    'banking_transaction_id' => $bankLine->id,
                    'transaction_id' => $transaction->id,
                    'amount_cents' => $amountCents,
                    'note' => $note,
                    'created_by' => $userId,
                ]);
            }

            $bankLine->forceFill([
                'reconciliation_status' => ReconciliationStatus::Unreviewed,
                'exclusion_note' => null,
                'excluded_by' => null,
                'excluded_at' => null,
            ])->save();

            $this->recalculate->execute($bankLine);

            return $allocation->refresh();
        });
    }
}
