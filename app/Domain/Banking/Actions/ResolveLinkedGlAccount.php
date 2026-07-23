<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use Illuminate\Validation\ValidationException;

final class ResolveLinkedGlAccount
{
    /**
     * @param  list<AccountType>|null  $allowedTypes
     */
    public function execute(int $teamId, int $glAccountId, ?array $allowedTypes = null, ?int $ignoreBankingAccountId = null): Account
    {
        $allowedTypes ??= [AccountType::Asset, AccountType::Liability];

        $account = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereKey($glAccountId)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'gl_account_id' => __('Select a valid ledger account for this company.'),
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'gl_account_id' => __('That ledger account is inactive.'),
            ]);
        }

        if (! in_array($account->type, $allowedTypes, true)) {
            throw ValidationException::withMessages([
                'gl_account_id' => __('Linked ledger account must be an asset or liability.'),
            ]);
        }

        $duplicate = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('gl_account_id', $account->id)
            ->when($ignoreBankingAccountId !== null, fn ($q) => $q->whereKeyNot($ignoreBankingAccountId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'gl_account_id' => __('Another banking account is already linked to that ledger account.'),
            ]);
        }

        return $account;
    }
}
