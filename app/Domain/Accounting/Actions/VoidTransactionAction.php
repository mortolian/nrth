<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VoidTransactionAction
{
    public function __construct(
        private readonly PostTransactionAction $postTransaction,
    ) {}

    public function execute(Transaction $transaction, string $reason): Transaction
    {
        if ($transaction->status !== TransactionStatus::Posted) {
            throw new \InvalidArgumentException('Only posted transactions can be voided.');
        }

        return DB::transaction(function () use ($transaction, $reason): Transaction {
            $reversal = Transaction::query()->create([
                'team_id' => $transaction->team_id,
                'type' => TransactionType::JournalAdjustment,
                'status' => TransactionStatus::Draft,
                'reference' => 'VOID-'.$transaction->getKey(),
                'description' => 'Reversal of transaction #'.$transaction->getKey().': '.$reason,
                'transaction_date' => Carbon::now()->toDateString(),
                'created_by' => $transaction->created_by,
            ]);

            foreach ($transaction->journalEntries as $line) {
                JournalEntry::query()->create([
                    'transaction_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'type' => $line->type->opposite(),
                    'amount_cents' => (int) $line->getRawOriginal('amount_cents'),
                    'currency' => (string) $line->getRawOriginal('currency'),
                    'description' => $line->description,
                ]);
            }

            $this->postTransaction->execute($reversal->fresh());

            $transaction->status = TransactionStatus::Void;
            $transaction->voided_at = Carbon::now();
            $transaction->voided_reason = $reason;
            $transaction->save();

            return $transaction->refresh();
        });
    }
}
