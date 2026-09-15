<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRecentTransactionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team, 2: Account, 3: BankingAccount}
     */
    private function teamWithExpenseAccounts(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        EnsureTeamSystemRoles::ensureFor($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);
        $bankGl = Account::factory()->for($team)->asset()->create(['code' => '1010', 'name' => 'Bank', 'is_system' => true]);
        Account::factory()->for($team)->asset()->create(['code' => '1020', 'name' => 'Cash on hand', 'is_system' => true]);
        Account::factory()->for($team)->liability()->create(['code' => '2000', 'name' => 'Accounts Payable', 'is_system' => true]);

        $category = Account::queryWithoutTeamScope()->where('team_id', $team->id)->where('code', '7500')->first();
        $this->assertNotNull($category);

        $banking = BankingAccount::factory()->for($team)->create([
            'name' => 'Operating bank',
            'gl_account_id' => $bankGl->id,
        ]);

        return [$user, $team, $category, $banking];
    }

    public function test_recent_transactions_include_vat_in_amount(): void
    {
        [, $team, $category, $banking] = $this->teamWithExpenseAccounts();
        TaxRate::factory()->for($team)->create();

        $this->post(route('expenses.store'), [
            'date' => '2026-09-15',
            'supplier' => 'Corner Cafe',
            'category_account_id' => $category->id,
            'description' => 'Coffee',
            'amount_excl_vat_cents' => 100_00,
            'vat_rate' => 'vat15',
            'vat_amount_cents' => 15_00,
            'paid_from_banking_account_id' => $banking->id,
            'reference' => null,
            'notes' => null,
        ])->assertRedirect(route('expenses.index'));

        $txn = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('recent_transactions', 1)
                ->where('recent_transactions.0.id', $txn->id)
                ->where('recent_transactions.0.amount_cents', 115_00)
            );
    }
}
