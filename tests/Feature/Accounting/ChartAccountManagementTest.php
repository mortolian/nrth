<?php

namespace Tests\Feature\Accounting;

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
