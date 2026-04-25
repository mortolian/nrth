<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Exceptions\UnbalancedTransactionException;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PostTransactionAction
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    public function execute(Transaction $transaction): Transaction
    {
        if ($transaction->status !== TransactionStatus::Draft) {
            throw new \InvalidArgumentException('Only draft transactions can be posted.');
        }

        if ($transaction->journalEntries()->doesntExist()) {
            throw new \InvalidArgumentException('Transaction has no journal lines.');
        }

        if (! $this->ledger->isBalanced($transaction)) {
            $debitSum = (int) $transaction->journalEntries()->where('type', EntryType::Debit)->sum('amount_cents');
            $creditSum = (int) $transaction->journalEntries()->where('type', EntryType::Credit)->sum('amount_cents');
            throw UnbalancedTransactionException::make($debitSum, $creditSum);
        }

        return DB::transaction(function () use ($transaction): Transaction {
            $transaction->status = TransactionStatus::Posted;
            $transaction->posted_at = Carbon::now();
            $transaction->save();

            return $transaction->refresh();
        });
    }
}
