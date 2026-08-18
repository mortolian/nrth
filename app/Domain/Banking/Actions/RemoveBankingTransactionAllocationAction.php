<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveBankingTransactionAllocationAction
{
    public function __construct(
        private readonly RecalculateBankingReconciliationStatus $recalculate,
    ) {}

    public function execute(BankingTransaction $bankLine, BankingTransactionAllocation $allocation): void
    {
        if ((int) $allocation->banking_transaction_id !== (int) $bankLine->id) {
            throw ValidationException::withMessages([
                'allocation' => __('That allocation does not belong to this bank line.'),
            ]);
        }

        DB::transaction(function () use ($bankLine, $allocation): void {
            $allocation->delete();
            $this->recalculate->execute($bankLine);
        });
    }
}
