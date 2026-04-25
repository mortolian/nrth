<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Models\Team;
use Brick\Money\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LedgerService
{
    /**
     * Posted and voided transactions both retain journal lines; voiding pairs an original (void) with a reversal (posted).
     */
    private function transactionAffectsLedger($query): void
    {
        $query->whereIn('status', [TransactionStatus::Posted, TransactionStatus::Void]);
    }

    public function getBalance(Account $account, ?Carbon $asOf = null): Money
    {
        $asOf = $asOf ?? Carbon::now();
        $currency = $this->defaultCurrencyForAccount($account);

        $debitSum = (int) JournalEntry::query()
            ->where('account_id', $account->id)
            ->where('type', EntryType::Debit)
            ->whereHas('transaction', function ($q) use ($account, $asOf): void {
                $q->where('team_id', $account->team_id);
                $this->transactionAffectsLedger($q);
                $q->whereDate('transaction_date', '<=', $asOf);
            })
            ->sum('amount_cents');

        $creditSum = (int) JournalEntry::query()
            ->where('account_id', $account->id)
            ->where('type', EntryType::Credit)
            ->whereHas('transaction', function ($q) use ($account, $asOf): void {
                $q->where('team_id', $account->team_id);
                $this->transactionAffectsLedger($q);
                $q->whereDate('transaction_date', '<=', $asOf);
            })
            ->sum('amount_cents');

        $netMinor = $account->type->isDebit()
            ? $debitSum - $creditSum
            : $creditSum - $debitSum;

        return Money::ofMinor($netMinor, $currency);
    }

    /**
     * Posted journal lines for the account in the date range, with running balance (normal-balance convention).
     *
     * @return Collection<int, object{
     *     transaction_date: string,
     *     reference: string|null,
     *     description: string|null,
     *     debit: Money|null,
     *     credit: Money|null,
     *     balance: Money
     * }>
     */
    public function getAccountStatement(Account $account, Carbon $from, Carbon $to): Collection
    {
        $currency = $this->defaultCurrencyForAccount($account);
        $runningMinor = $this->getBalance($account, $from->copy()->subDay())->getMinorAmount()->toInt();

        $lines = JournalEntry::query()
            ->with('transaction')
            ->where('account_id', $account->id)
            ->whereHas('transaction', function ($q) use ($account, $from, $to): void {
                $q->where('team_id', $account->team_id);
                $this->transactionAffectsLedger($q);
                $q->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->get()
            ->sortBy(fn (JournalEntry $line): string => sprintf(
                '%s-%010d-%010d',
                $line->transaction->transaction_date->toDateString(),
                $line->transaction_id,
                $line->getKey()
            ))
            ->values();

        return $lines->map(function (JournalEntry $line) use ($account, $currency, &$runningMinor): object {
            $cents = (int) $line->getRawOriginal('amount_cents');
            $delta = $account->type->isDebit()
                ? ($line->type === EntryType::Debit ? $cents : -$cents)
                : ($line->type === EntryType::Credit ? $cents : -$cents);
            $runningMinor += $delta;

            $debit = $line->type === EntryType::Debit
                ? Money::ofMinor($cents, $currency)
                : null;
            $credit = $line->type === EntryType::Credit
                ? Money::ofMinor($cents, $currency)
                : null;

            $tx = $line->transaction;

            return (object) [
                'transaction_date' => $tx->transaction_date->toDateString(),
                'reference' => $tx->reference,
                'description' => $line->description ?? $tx->description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => Money::ofMinor($runningMinor, $currency),
            ];
        });
    }

    public function isBalanced(Transaction $transaction): bool
    {
        $debitSum = (int) $transaction->journalEntries()->where('type', EntryType::Debit)->sum('amount_cents');
        $creditSum = (int) $transaction->journalEntries()->where('type', EntryType::Credit)->sum('amount_cents');

        return $debitSum === $creditSum;
    }

    /**
     * Per-account totals of posted debits and credits up to and including {@see $asOf}.
     *
     * @return Collection<int, object{
     *     account: Account,
     *     debit_total: Money,
     *     credit_total: Money
     * }>
     */
    public function trialBalance(Team $team, Carbon $asOf): Collection
    {
        $currency = 'ZAR';

        return Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($team, $asOf, $currency): object {
                $debitSum = (int) JournalEntry::query()
                    ->where('account_id', $account->id)
                    ->where('type', EntryType::Debit)
                    ->whereHas('transaction', function ($q) use ($team, $asOf): void {
                        $q->where('team_id', $team->id);
                        $this->transactionAffectsLedger($q);
                        $q->whereDate('transaction_date', '<=', $asOf);
                    })
                    ->sum('amount_cents');

                $creditSum = (int) JournalEntry::query()
                    ->where('account_id', $account->id)
                    ->where('type', EntryType::Credit)
                    ->whereHas('transaction', function ($q) use ($team, $asOf): void {
                        $q->where('team_id', $team->id);
                        $this->transactionAffectsLedger($q);
                        $q->whereDate('transaction_date', '<=', $asOf);
                    })
                    ->sum('amount_cents');

                return (object) [
                    'account' => $account,
                    'debit_total' => Money::ofMinor($debitSum, $currency),
                    'credit_total' => Money::ofMinor($creditSum, $currency),
                ];
            });
    }

    private function defaultCurrencyForAccount(Account $account): string
    {
        $code = JournalEntry::query()
            ->where('account_id', $account->id)
            ->value('currency');

        return $code ?: 'ZAR';
    }
}
