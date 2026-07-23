<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Models\BankingAccount;
use Illuminate\Support\Facades\DB;

final class CreateBankingAccountAction
{
    public function __construct(
        private readonly ResolveLinkedGlAccount $resolveLinkedGlAccount,
    ) {}

    /**
     * @param  array{
     *     team_id: int,
     *     name: string,
     *     bank_name?: string|null,
     *     account_number_last4?: string|null,
     *     currency?: string,
     *     type?: string|null,
     *     gl_account_id: int,
     *     is_active?: bool
     * }  $data
     */
    public function execute(array $data): BankingAccount
    {
        $gl = $this->resolveLinkedGlAccount->execute(
            (int) $data['team_id'],
            (int) $data['gl_account_id'],
        );

        return DB::transaction(fn () => BankingAccount::queryWithoutTeamScope()->create([
            'team_id' => $data['team_id'],
            'name' => $data['name'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number_last4' => $data['account_number_last4'] ?? null,
            'currency' => $data['currency'] ?? 'ZAR',
            'type' => $data['type'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'gl_account_id' => $gl->id,
        ]));
    }
}
