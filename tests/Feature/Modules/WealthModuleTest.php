<?php

namespace Tests\Feature\Modules;

use App\Models\Team;
use App\Models\User;
use App\Support\Modules\ModuleCatalog;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WealthModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_wealth_is_disabled_by_default_and_returns_forbidden(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->assertFalse($owner->currentTeam->moduleEnabled(ModuleCatalog::WEALTH));

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertForbidden();
    }

    public function test_owner_can_enable_wealth_and_open_placeholder(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $this->actingAs($owner)
            ->put(route('settings.features.update'), [
                'modules' => [
                    ['name' => ModuleCatalog::WEALTH, 'enabled' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertTrue($team->fresh()->moduleEnabled(ModuleCatalog::WEALTH));

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertOk();
    }

    public function test_disabling_wealth_hides_route_again_without_error_on_features_page(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertOk();

        $this->actingAs($owner)
            ->put(route('settings.features.update'), [
                'modules' => [
                    ['name' => ModuleCatalog::WEALTH, 'enabled' => false],
                ],
            ])
            ->assertRedirect();

        $this->assertFalse($team->fresh()->moduleEnabled(ModuleCatalog::WEALTH));

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('settings.features'))
            ->assertOk();
    }

    public function test_viewer_cannot_open_features_settings(): void
    {
        [, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('settings.features'))
            ->assertForbidden();
    }

    public function test_viewer_can_open_wealth_when_module_enabled(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);
        $owner->currentTeam->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $this->actingAs($viewer)
            ->get(route('wealth.index'))
            ->assertOk();
    }

    public function test_viewer_is_forbidden_when_wealth_module_disabled(): void
    {
        [, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('wealth.index'))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function ownerAndMember(string $role): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        /** @var Team $team */
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return [$owner, $member->fresh()];
    }
}
