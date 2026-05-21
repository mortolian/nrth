<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Models\BankingAccount;
use Illuminate\Support\Facades\DB;

final class CreateBankingAccountAction
{
    /**
     * @param  array{
     *     team_id: int,
     *     name: string,
     *     bank_name?: string|null,
     *     account_number_last4?: string|null,
     *     currency?: string,
     *     type?: string|null
     * }  $data
     */
    public function execute(array $data): BankingAccount
    {
        return DB::transaction(fn () => BankingAccount::queryWithoutTeamScope()->create([
            'team_id' => $data['team_id'],
            'name' => $data['name'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number_last4' => $data['account_number_last4'] ?? null,
            'currency' => $data['currency'] ?? 'ZAR',
            'type' => $data['type'] ?? null,
            'is_active' => true,
        ]));
    }
}
