<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use Illuminate\Support\Facades\DB;

final class ResetBankingTransactionReconciliationAction
{
    public function execute(BankingTransaction $bankLine): BankingTransaction
    {
        return DB::transaction(function () use ($bankLine): BankingTransaction {
            BankingTransactionAllocation::queryWithoutTeamScope()
                ->where('banking_transaction_id', $bankLine->id)
                ->delete();

            $bankLine->forceFill([
                'reconciliation_status' => ReconciliationStatus::Unreviewed,
                'exclusion_note' => null,
                'excluded_by' => null,
                'excluded_at' => null,
            ])->save();

            return $bankLine->refresh();
        });
    }
}
