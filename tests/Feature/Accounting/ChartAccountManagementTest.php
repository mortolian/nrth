<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_seed_default_chart_when_empty(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $this->post(route('accounting.accounts.seed-default'))
            ->assertRedirect(route('accounting.accounts.index'));

        $this->assertDatabaseHas('accounts', [
            'team_id' => $user->current_team_id,
            'code' => '1010',
        ]);
    }

    public function test_owner_can_create_custom_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $this->post(route('accounting.accounts.store'), [
            'code' => '7999',
            'name' => 'Miscellaneous',
            'description' => null,
            'type' => 'expense',
            'parent_id' => null,
        ])->assertRedirect(route('accounting.accounts.index'));

        $this->assertDatabaseHas('accounts', [
            'team_id' => $team->id,
            'code' => '7999',
            'name' => 'Miscellaneous',
            'is_system' => false,
        ]);
    }

    public function test_create_form_includes_suggested_account_codes(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $this->get(route('accounting.accounts.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/Accounts/Form')
                ->where('isEditing', false)
                ->has('suggested_codes.expense')
                ->where('suggested_codes.expense', '5910')
                ->has('code_accounts'));
    }

    public function test_owner_can_rename_system_account_but_not_change_code(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $bank = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1010')
            ->firstOrFail();

        $this->put(route('accounting.accounts.update', $bank), [
            'code' => '1010',
            'name' => 'Cheque account',
            'description' => 'Operating cheque account',
            'parent_id' => $bank->parent_id,
        ])->assertRedirect(route('accounting.accounts.index'));

        $bank->refresh();
        $this->assertSame('Cheque account', $bank->name);
        $this->assertSame('1010', $bank->code);
        $this->assertSame('Operating cheque account', $bank->description);
        $this->assertTrue($bank->is_system);

        // Attempting to change the code is ignored for system accounts (code stays fixed).
        $this->put(route('accounting.accounts.update', $bank), [
            'code' => '9999',
            'name' => 'Cheque account',
            'description' => 'Operating cheque account',
            'parent_id' => $bank->parent_id,
        ])->assertRedirect(route('accounting.accounts.index'));

        $bank->refresh();
        $this->assertSame('1010', $bank->code);
        $this->assertSame('Cheque account', $bank->name);
    }

    public function test_owner_can_change_type_on_unused_custom_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $account = Account::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'code' => '7998',
            'name' => 'Misplaced',
            'type' => 'expense',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->get(route('accounting.accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/Accounts/Form')
                ->where('can_change_type', true));

        $this->put(route('accounting.accounts.update', $account), [
            'code' => '7998',
            'name' => 'Misplaced',
            'description' => null,
            'type' => 'asset',
            'parent_id' => null,
        ])->assertRedirect(route('accounting.accounts.index'));

        $this->assertSame('asset', $account->fresh()->type->value);
    }

    public function test_chart_index_includes_management_flags(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $this->get(route('accounting.accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Accounting/Accounts/Index')
                ->where('account_count', 0)
                ->where('can_manage', true));
    }
}
