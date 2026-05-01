<?php

namespace Database\Seeders;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class DefaultChartOfAccountsSeeder
{
    /**
     * South African standard chart of accounts (system accounts).
     *
     * Includes a 1000-series parent for bank and cash, per the project chart spec.
     *
     * @return list<array{code: string, name: string, description: string|null, type: AccountType, parent_code: string|null}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => '1000', 'name' => 'Bank and cash', 'description' => 'Cash and bank balances (summary)', 'type' => AccountType::Asset, 'parent_code' => null],
            ['code' => '1010', 'name' => 'Bank', 'description' => 'Operating bank accounts', 'type' => AccountType::Asset, 'parent_code' => '1000'],
            ['code' => '1020', 'name' => 'Cash on hand', 'description' => 'Petty cash', 'type' => AccountType::Asset, 'parent_code' => '1000'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'description' => 'Trade debtors', 'type' => AccountType::Asset, 'parent_code' => null],
            ['code' => '1200', 'name' => 'VAT Input', 'description' => 'VAT recoverable (input tax)', 'type' => AccountType::Asset, 'parent_code' => null],
            ['code' => '2000', 'name' => 'Accounts Payable', 'description' => 'Trade creditors', 'type' => AccountType::Liability, 'parent_code' => null],
            ['code' => '2100', 'name' => 'VAT Output', 'description' => 'VAT payable (output tax)', 'type' => AccountType::Liability, 'parent_code' => null],
            ['code' => '2200', 'name' => 'Income Tax Payable', 'description' => 'Income tax provision', 'type' => AccountType::Liability, 'parent_code' => null],
            ['code' => '3000', 'name' => "Owner's Equity", 'description' => 'Owner capital', 'type' => AccountType::Equity, 'parent_code' => null],
            ['code' => '3100', 'name' => 'Retained Earnings', 'description' => 'Accumulated profits', 'type' => AccountType::Equity, 'parent_code' => null],
            ['code' => '4000', 'name' => 'Service Revenue', 'description' => 'Primary trading income', 'type' => AccountType::Income, 'parent_code' => null],
            ['code' => '4900', 'name' => 'Other Income', 'description' => 'Non-operating income', 'type' => AccountType::Income, 'parent_code' => null],
            ['code' => '4950', 'name' => 'Foreign Exchange Gain', 'description' => 'Realised foreign exchange gains', 'type' => AccountType::Income, 'parent_code' => null],
            ['code' => '5000', 'name' => 'Cost of Sales', 'description' => 'Direct costs', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5100', 'name' => 'Salaries', 'description' => 'Wages and salaries', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5200', 'name' => 'Rent', 'description' => 'Premises rent', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5300', 'name' => 'Utilities', 'description' => 'Water, electricity, connectivity', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5400', 'name' => 'Travel', 'description' => 'Travel and subsistence', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5500', 'name' => 'Home Office', 'description' => 'Home office expenses', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5600', 'name' => 'Professional Fees', 'description' => 'Accounting, legal, consulting', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5700', 'name' => 'Bank Charges', 'description' => 'Bank and payment fees', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5800', 'name' => 'Depreciation', 'description' => 'Depreciation expense', 'type' => AccountType::Expense, 'parent_code' => null],
            ['code' => '5900', 'name' => 'Foreign Exchange Loss', 'description' => 'Realised foreign exchange losses', 'type' => AccountType::Expense, 'parent_code' => null],
        ];
    }

    /**
     * Idempotent: creates the full default chart when core accounts (e.g. Bank 1010) are missing.
     * Covers teams that never completed onboarding or install seeding.
     */
    public function ensureForTeam(Team $team): void
    {
        if (Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1010')
            ->exists()) {
            return;
        }

        $this->runForTeam($team);
    }

    public function runForTeam(Team $team): void
    {
        DB::transaction(function () use ($team): void {
            $idByCode = [];

            foreach (self::definitions() as $row) {
                if ($row['parent_code'] !== null) {
                    continue;
                }

                $account = Account::queryWithoutTeamScope()->updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'code' => $row['code'],
                    ],
                    [
                        'parent_id' => null,
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'type' => $row['type'],
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );

                $idByCode[$row['code']] = $account->getKey();
            }

            foreach (self::definitions() as $row) {
                if ($row['parent_code'] === null) {
                    continue;
                }

                $parentId = $idByCode[$row['parent_code']] ?? null;

                $account = Account::queryWithoutTeamScope()->updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'code' => $row['code'],
                    ],
                    [
                        'parent_id' => $parentId,
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'type' => $row['type'],
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );

                $idByCode[$row['code']] = $account->getKey();
            }
        });
    }
}
