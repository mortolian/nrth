<?php

namespace App\Domain\SampleData;

use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Invoicing\Models\Client;
use App\Models\Team;
use App\Models\User;

final class SampleContext
{
    /**
     * @param  array<string, Client>  $clients
     * @param  array<string, Account>  $accounts
     */
    public function __construct(
        public readonly User $user,
        public readonly Team $team,
        public readonly SampleCalendar $calendar,
        public readonly BankingAccount $bank,
        public array $clients = [],
        public array $accounts = [],
    ) {}

    public function account(string $code): Account
    {
        $account = $this->accounts[$code] ?? null;

        if ($account === null) {
            throw new \RuntimeException("Sample chart is missing account {$code}.");
        }

        return $account;
    }
}
