<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteTransactionAction
{
    public static function deletionBlockReason(Transaction $transaction): ?string
    {
        if ($transaction->status === TransactionStatus::Void) {
            return __('Void transactions cannot be deleted.');
        }

        if ($transaction->status === TransactionStatus::Draft) {
            return null;
        }

        if ($transaction->status !== TransactionStatus::Posted) {
            return __('Only draft or eligible posted transactions can be deleted.');
        }

        if ($transaction->type === TransactionType::OpeningBalance) {
            return __('Opening balance transactions cannot be deleted.');
        }

        if ($transaction->type === TransactionType::JournalAdjustment
            && str_starts_with((string) $transaction->reference, 'VOID-')) {
            return __('Void reversal entries cannot be deleted.');
        }

        if (Payment::queryWithoutTeamScope()->where('transaction_id', $transaction->id)->exists()) {
            return __('This transaction is linked to an invoice payment and cannot be deleted.');
        }

        if (Invoice::queryWithoutTeamScope()->where('transaction_id', $transaction->id)->exists()) {
            return __('This transaction is linked to an invoice and cannot be deleted.');
        }

        return null;
    }

    public static function canDelete(Transaction $transaction): bool
    {
        return self::deletionBlockReason($transaction) === null;
    }

    public function execute(Transaction $transaction): void
    {
        $reason = self::deletionBlockReason($transaction);
        if ($reason !== null) {
            throw ValidationException::withMessages([
                'transaction' => $reason,
            ]);
        }

        DB::transaction(function () use ($transaction): void {
            $transaction->loadMissing('media');
            $transaction->clearMediaCollection('attachments');
            $transaction->delete();
        });
    }
}
