<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use App\Models\Team;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnsureDefaultBankingAccount
{
    /**
     * Ensure the team has an active Banking account linked to GL Bank (1010).
     */
    public function execute(Team $team): BankingAccount
    {
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $bankGl = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1010')
            ->first();

        if ($bankGl === null) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('Missing Bank chart account (1010). Open Chart of accounts and restore the default chart.'),
            ]);
        }

        if (! $bankGl->is_active) {
            $bankGl->forceFill(['is_active' => true])->save();
        }

        if ($bankGl->type !== AccountType::Asset) {
            throw ValidationException::withMessages([
                'banking_account_id' => __('Chart account 1010 must be an asset account.'),
            ]);
        }

        $existing = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('gl_account_id', $bankGl->id)
            ->first();

        if ($existing !== null) {
            if (! $existing->is_active) {
                $existing->forceFill(['is_active' => true])->save();
            }

            return $existing;
        }

        return DB::transaction(fn () => BankingAccount::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'name' => 'Bank',
            'bank_name' => null,
            'account_number_last4' => null,
            'currency' => 'ZAR',
            'type' => 'cheque',
            'is_active' => true,
            'gl_account_id' => $bankGl->id,
        ]));
    }
}
