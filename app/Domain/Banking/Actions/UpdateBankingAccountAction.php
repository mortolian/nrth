<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Models\BankingAccount;
use Illuminate\Support\Facades\DB;

final class UpdateBankingAccountAction
{
    public function __construct(
        private readonly ResolveLinkedGlAccount $resolveLinkedGlAccount,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     bank_name?: string|null,
     *     account_number_last4?: string|null,
     *     currency?: string,
     *     type?: string|null,
     *     is_active?: bool,
     *     gl_account_id: int
     * }  $data
     */
    public function execute(BankingAccount $account, array $data): BankingAccount
    {
        $gl = $this->resolveLinkedGlAccount->execute(
            (int) $account->team_id,
            (int) $data['gl_account_id'],
            ignoreBankingAccountId: (int) $account->id,
        );

        return DB::transaction(function () use ($account, $data, $gl): BankingAccount {
            $account->forceFill([
                'name' => $data['name'],
                'bank_name' => $data['bank_name'] ?? null,
                'account_number_last4' => $data['account_number_last4'] ?? null,
                'currency' => $data['currency'] ?? $account->currency,
                'type' => $data['type'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? $account->is_active),
                'gl_account_id' => $gl->id,
            ])->save();

            return $account->refresh();
        });
    }
}
