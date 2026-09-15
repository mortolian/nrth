<?php

namespace Tests\Feature\Expenses;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Carbon\Carbon;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team, 2: Account, 3: BankingAccount}
     */
    private function teamWithExpenseAccounts(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);
        EnsureTeamSystemRoles::ensureFor($team);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);

        $category = Account::factory()->for($team)->expense()->create([
            'code' => '7500',
            'name' => 'Rent',
        ]);
        $bankGl = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1010')
            ->first();
        if ($bankGl === null) {
            $bankGl = Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank']);
        }
        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'Operating bank',
            'gl_account_id' => $bankGl->id,
            'is_active' => true,
        ]);

        return [$owner, $team, $category, $banking];
    }

    public function test_generate_creates_expense_and_advances_next_run(): void
    {
        [, $team, $category, $banking] = $this->teamWithExpenseAccounts();

        $recurring = RecurringExpense::factory()->create([
            'team_id' => $team->id,
            'supplier_name' => 'Landlord Co',
            'category_account_id' => $category->id,
            'paid_from_banking_account_id' => $banking->id,
            'frequency' => RecurringFrequency::Monthly,
            'generate_on_day' => 1,
            'next_run_date' => '2026-07-01',
            'period_offset_months' => 0,
            'description' => 'Rent for {{month_year}}',
            'amount_excl_vat_cents' => 1500000,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
        ]);

        $expense = app(GenerateRecurringExpenseAction::class)->execute($recurring, Carbon::parse('2026-07-01'));
        $this->assertNotNull($expense);
        $this->assertSame(TransactionType::Expense, $expense->type);
        $this->assertSame('Rent for July 2026', $expense->description);
        $this->assertSame('2026-07-01', $expense->transaction_date->toDateString());
        $this->assertSame($recurring->id, (int) $expense->recurring_expense_id);
        $this->assertSame('Landlord Co', $expense->reference);

        $recurring->refresh();
        $this->assertSame(1, (int) $recurring->generated_count);
        $this->assertSame('2026-08-01', $recurring->next_run_date->toDateString());
    }

    public function test_on_hold_and_count_limit(): void
    {
        [, $team, $category, $banking] = $this->teamWithExpenseAccounts();

        $held = RecurringExpense::factory()->create([
            'team_id' => $team->id,
            'category_account_id' => $category->id,
            'paid_from_banking_account_id' => $banking->id,
            'status' => RecurringExpenseStatus::OnHold,
            'next_run_date' => now()->toDateString(),
        ]);
        $this->assertNull(app(GenerateRecurringExpenseAction::class)->execute($held));

        $limited = RecurringExpense::factory()->create([
            'team_id' => $team->id,
            'category_account_id' => $category->id,
            'paid_from_banking_account_id' => $banking->id,
            'limit_type' => RecurringLimitType::Count,
            'limit_count' => 1,
            'next_run_date' => '2026-07-01',
            'generate_on_day' => 1,
            'description' => 'One-off',
            'amount_excl_vat_cents' => 1000,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
        ]);
        app(GenerateRecurringExpenseAction::class)->execute($limited, Carbon::parse('2026-07-01'));
        $limited->refresh();
        $this->assertSame(RecurringExpenseStatus::Completed, $limited->status);
    }

    public function test_artisan_command_generates_due_templates(): void
    {
        [, $team, $category, $banking] = $this->teamWithExpenseAccounts();

        RecurringExpense::factory()->create([
            'team_id' => $team->id,
            'category_account_id' => $category->id,
            'paid_from_banking_account_id' => $banking->id,
            'next_run_date' => now()->toDateString(),
            'description' => 'Due now',
            'amount_excl_vat_cents' => 2500,
            'vat_rate' => 'no_vat',
            'vat_amount_cents' => 0,
        ]);

        $this->artisan('expenses:generate-recurring')
            ->expectsOutputToContain('Generated 1 recurring expense(s).')
            ->assertSuccessful();

        $this->assertSame(1, Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::Expense->value)
            ->count());
    }

    public function test_viewer_cannot_manage_recurring(): void
    {
        [$owner] = $this->teamWithExpenseAccounts();
        $viewer = User::factory()->create();
        $owner->currentTeam->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $owner->current_team_id])->save();

        $this->actingAs($viewer)
            ->get(route('expenses.recurring.create'))
            ->assertForbidden();
    }
}
