<?php

namespace Database\Factories;

use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringExpense>
 */
class RecurringExpenseFactory extends Factory
{
    protected $model = RecurringExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'supplier_id' => null,
            'supplier_name' => 'Recurring vendor',
            'category_account_id' => Account::factory()->expense(),
            'paid_from_banking_account_id' => BankingAccount::factory(),
            'status' => RecurringExpenseStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'generate_on_day' => 1,
            'generate_on_last_day' => false,
            'limit_type' => RecurringLimitType::None,
            'generated_count' => 0,
            'next_run_date' => now()->toDateString(),
            'period_offset_months' => 0,
            'description' => 'Rent for {{month_year}}',
            'amount_excl_vat_cents' => 1000000,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
        ];
    }
}
