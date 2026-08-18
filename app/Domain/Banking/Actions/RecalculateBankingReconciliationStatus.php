<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use App\Domain\Banking\Services\BankingReconciliationTotals;

final class RecalculateBankingReconciliationStatus
{
    public function __construct(
        private readonly BankingReconciliationTotals $totals,
    ) {}

    public function execute(BankingTransaction $bankLine): BankingTransaction
    {
        $this->dropInvalidAllocations($bankLine);

        if ($bankLine->reconciliation_status === ReconciliationStatus::Excluded) {
            return $bankLine->refresh();
        }

        $allocated = $this->totals->allocatedBankCents($bankLine);
        $total = $this->totals->bankAmountCents($bankLine);

        $status = ReconciliationStatus::Unreviewed;
        if ($allocated > 0 && $allocated < $total) {
            $status = ReconciliationStatus::PartiallyMatched;
        } elseif ($allocated > 0 && $allocated >= $total) {
            $status = ReconciliationStatus::Matched;
        }

        $bankLine->forceFill([
            'reconciliation_status' => $status,
        ])->save();

        return $bankLine->refresh();
    }

    private function dropInvalidAllocations(BankingTransaction $bankLine): void
    {
        $allocations = BankingTransactionAllocation::queryWithoutTeamScope()
            ->where('banking_transaction_id', $bankLine->id)
            ->with('transaction')
            ->get();

        foreach ($allocations as $allocation) {
            $transaction = $allocation->transaction;
            if ($transaction === null || $transaction->status !== TransactionStatus::Posted) {
                $allocation->delete();
            }
        }
    }
}
