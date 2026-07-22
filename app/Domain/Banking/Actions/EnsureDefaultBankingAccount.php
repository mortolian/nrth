<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use App\Models\Team;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $existing = $this->findLinked($team, (int) $bankGl->id);
        if ($existing !== null) {
            return $this->activate($existing);
        }

        try {
            return DB::transaction(function () use ($team, $bankGl) {
                $locked = $this->findLinked($team, (int) $bankGl->id, lock: true);
                if ($locked !== null) {
                    return $this->activate($locked);
                }

                return BankingAccount::queryWithoutTeamScope()->create([
                    'team_id' => $team->id,
                    'name' => 'Bank',
                    'bank_name' => null,
                    'account_number_last4' => null,
                    'currency' => 'ZAR',
                    'type' => 'cheque',
                    'is_active' => true,
                    'gl_account_id' => $bankGl->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $created = $this->findLinked($team, (int) $bankGl->id);
            if ($created === null) {
                throw ValidationException::withMessages([
                    'banking_account_id' => __('Could not prepare the default Bank account. Try again.'),
                ]);
            }

            return $this->activate($created);
        }
    }

    private function findLinked(Team $team, int $glAccountId, bool $lock = false): ?BankingAccount
    {
        $query = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('gl_account_id', $glAccountId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function activate(BankingAccount $account): BankingAccount
    {
        if (! $account->is_active) {
            $account->forceFill(['is_active' => true])->save();
        }

        return $account;
    }
}
