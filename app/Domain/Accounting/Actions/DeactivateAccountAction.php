<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Exceptions\SystemAccountProtectedException;
use App\Domain\Accounting\Models\Account;
use Illuminate\Support\Facades\DB;

class DeactivateAccountAction
{
    public function execute(Account $account): Account
    {
        if ($account->is_system) {
            throw SystemAccountProtectedException::cannotDeactivate();
        }

        return DB::transaction(function () use ($account): Account {
            $account->is_active = false;
            $account->save();

            return $account->refresh();
        });
    }
}
