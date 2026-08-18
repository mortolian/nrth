<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Banking\Models\BankingTransactionAllocation;
use Illuminate\Support\Facades\DB;

final class ExcludeBankingTransactionAction
{
    public function execute(BankingTransaction $bankLine, int $userId, ?string $note = null): BankingTransaction
    {
        return DB::transaction(function () use ($bankLine, $userId, $note): BankingTransaction {
            BankingTransactionAllocation::queryWithoutTeamScope()
                ->where('banking_transaction_id', $bankLine->id)
                ->delete();

            $bankLine->forceFill([
                'reconciliation_status' => ReconciliationStatus::Excluded,
                'exclusion_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'excluded_by' => $userId,
                'excluded_at' => now(),
            ])->save();

            return $bankLine->refresh();
        });
    }
}
