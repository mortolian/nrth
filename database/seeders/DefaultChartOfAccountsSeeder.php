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
     * @return list<array{code: string, name: string, description: string|null, type: AccountType}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => '1000', 'name' => 'Bank', 'description' => 'Operating bank accounts', 'type' => AccountType::Asset],
            ['code' => '1010', 'name' => 'Cash on Hand', 'description' => 'Petty cash', 'type' => AccountType::Asset],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'description' => 'Trade debtors', 'type' => AccountType::Asset],
            ['code' => '1200', 'name' => 'VAT Input', 'description' => 'VAT recoverable (input tax)', 'type' => AccountType::Asset],
            ['code' => '2000', 'name' => 'Accounts Payable', 'description' => 'Trade creditors', 'type' => AccountType::Liability],
            ['code' => '2100', 'name' => 'VAT Output', 'description' => 'VAT payable (output tax)', 'type' => AccountType::Liability],
            ['code' => '2200', 'name' => 'Income Tax Payable', 'description' => 'Income tax provision', 'type' => AccountType::Liability],
            ['code' => '3000', 'name' => "Owner's Equity", 'description' => 'Owner capital', 'type' => AccountType::Equity],
            ['code' => '3100', 'name' => 'Retained Earnings', 'description' => 'Accumulated profits', 'type' => AccountType::Equity],
            ['code' => '4000', 'name' => 'Service Revenue', 'description' => 'Primary trading income', 'type' => AccountType::Income],
            ['code' => '4900', 'name' => 'Other Income', 'description' => 'Non-operating income', 'type' => AccountType::Income],
            ['code' => '5000', 'name' => 'Cost of Sales', 'description' => 'Direct costs', 'type' => AccountType::Expense],
            ['code' => '5100', 'name' => 'Salaries', 'description' => 'Wages and salaries', 'type' => AccountType::Expense],
            ['code' => '5200', 'name' => 'Rent', 'description' => 'Premises rent', 'type' => AccountType::Expense],
            ['code' => '5300', 'name' => 'Utilities', 'description' => 'Water, electricity, connectivity', 'type' => AccountType::Expense],
            ['code' => '5400', 'name' => 'Travel', 'description' => 'Travel and subsistence', 'type' => AccountType::Expense],
            ['code' => '5500', 'name' => 'Home Office', 'description' => 'Home office expenses', 'type' => AccountType::Expense],
            ['code' => '5600', 'name' => 'Professional Fees', 'description' => 'Accounting, legal, consulting', 'type' => AccountType::Expense],
            ['code' => '5700', 'name' => 'Bank Charges', 'description' => 'Bank and payment fees', 'type' => AccountType::Expense],
            ['code' => '5800', 'name' => 'Depreciation', 'description' => 'Depreciation expense', 'type' => AccountType::Expense],
        ];
    }

    public function runForTeam(Team $team): void
    {
        DB::transaction(function () use ($team): void {
            foreach (self::definitions() as $row) {
                Account::queryWithoutTeamScope()->updateOrCreate(
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
            }
        });
    }
}
