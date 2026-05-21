<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\DTOs\ParsedTransactionDTO;
use App\Domain\Banking\Models\BankingTransaction;

final class BankingDuplicateDetector
{
    public function duplicateKey(
        int $accountId,
        string $transactionDate,
        string $amount,
        string $description,
        ?string $reference = null,
    ): string {
        $parts = [
            (string) $accountId,
            $transactionDate,
            number_format((float) $amount, 2, '.', ''),
            mb_strtolower(trim($description)),
        ];

        if ($reference !== null && $reference !== '') {
            $parts[] = mb_strtolower(trim($reference));
        }

        return hash('sha256', implode('|', $parts));
    }

    public function sourceHash(ParsedTransactionDTO $transaction): string
    {
        return hash('sha256', json_encode([
            'transaction_date' => $transaction->transactionDate,
            'value_date' => $transaction->valueDate,
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'amount' => $transaction->amount,
            'direction' => $transaction->direction->value,
            'running_balance' => $transaction->runningBalance,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $duplicateKeys
     * @return array<string, true>
     */
    public function existingKeysForAccount(int $accountId, array $duplicateKeys): array
    {
        if ($duplicateKeys === []) {
            return [];
        }

        return BankingTransaction::queryWithoutTeamScope()
            ->where('account_id', $accountId)
            ->whereIn('duplicate_key', $duplicateKeys)
            ->pluck('duplicate_key')
            ->mapWithKeys(fn (string $key) => [$key => true])
            ->all();
    }
}
