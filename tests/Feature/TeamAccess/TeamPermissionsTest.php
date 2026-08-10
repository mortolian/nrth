<?php

namespace Tests\Feature\TeamAccess;

use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use App\Support\TeamAccess\TeamAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_create_invoice(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('invoicing.invoices.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('invoicing.invoices.store'), [])
            ->assertForbidden();
    }

    public function test_viewer_can_view_invoice_index(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('invoicing.invoices.index'))
            ->assertOk();
    }

    public function test_accountant_cannot_delete_expense_and_cannot_open_business_settings(): void
    {
        [$owner, $accountant] = $this->ownerAndMember(RolePresets::ACCOUNTANT);

        $this->assertFalse(TeamAccess::allows($accountant, $owner->currentTeam, 'expenses.delete'));
        $this->assertFalse(TeamAccess::allows($accountant, $owner->currentTeam, 'settings.business'));
        $this->assertFalse(TeamAccess::allows($accountant, $owner->currentTeam, 'settings.team'));
        $this->assertTrue(TeamAccess::allows($accountant, $owner->currentTeam, 'expenses.manage'));

        $this->actingAs($accountant)
            ->get(route('settings.business'))
            ->assertForbidden();

        $this->actingAs($accountant)
            ->get(route('settings.team'))
            ->assertForbidden();
    }

    public function test_viewer_cannot_open_business_or_team_settings(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->assertFalse(TeamAccess::allows($viewer, $owner->currentTeam, 'settings.business'));
        $this->assertFalse(TeamAccess::allows($viewer, $owner->currentTeam, 'settings.team'));

        $this->actingAs($viewer)
            ->get(route('settings.business'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('settings.team'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('profile.show'))
            ->assertOk();
    }

    public function test_inertia_forbidden_visit_redirects_with_error_flash_instead_of_modal(): void
    {
        [, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        // Non-Inertia clients still receive a real 403.
        $this->actingAs($viewer)
            ->get(route('budgeting.create'))
            ->assertForbidden();

        // Inertia visits get a hard location response + flash (avoids a stuck SPA visit).
        $this->actingAs($viewer)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->from(route('dashboard'))
            ->post(route('invoicing.invoices.store'), [])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('dashboard'))
            ->assertSessionHas('error', 'You do not have permission to do that.');
    }

    public function test_custom_role_with_settings_permissions_can_open_settings_pages(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $role = TeamRole::query()->create([
            'team_id' => $team->id,
            'key' => 'office-admin',
            'name' => 'Office admin',
            'description' => 'Business and team settings',
            'permissions' => ['settings.business', 'settings.team'],
            'is_system' => false,
        ]);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role->key]);
        $member->forceFill(['current_team_id' => $team->id])->save();
        $member = $member->fresh();

        $this->assertTrue(TeamAccess::allows($member, $team->fresh(), 'settings.business'));
        $this->assertTrue(TeamAccess::allows($member, $team->fresh(), 'settings.team'));

        $this->actingAs($member)
            ->get(route('settings.business'))
            ->assertOk();

        $this->actingAs($member)
            ->get(route('settings.team'))
            ->assertOk();
    }

    public function test_custom_role_with_only_reports_view_can_open_profit_loss_but_not_create_invoices(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $role = TeamRole::query()->create([
            'team_id' => $team->id,
            'key' => 'reporter',
            'name' => 'Reporter',
            'description' => 'Reports only',
            'permissions' => ['reports.view'],
            'is_system' => false,
        ]);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role->key]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->assertTrue(TeamAccess::allows($member->fresh(), $team->fresh(), 'reports.view'));
        $this->assertFalse(TeamAccess::allows($member->fresh(), $team->fresh(), 'invoices.manage'));

        $this->actingAs($member->fresh())
            ->get(route('reports.profit-loss'))
            ->assertOk();

        $this->actingAs($member->fresh())
            ->get(route('invoicing.invoices.create'))
            ->assertForbidden();
    }

    public function test_owner_can_create_custom_role(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);

        $this->actingAs($owner)
            ->post(route('settings.team.roles.store'), [
                'name' => 'Bookkeeper',
                'description' => 'Expenses only',
                'permissions' => ['expenses.view', 'expenses.manage'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('team_roles', [
            'team_id' => $owner->currentTeam->id,
            'key' => 'bookkeeper',
            'is_system' => false,
        ]);
    }

    public function test_system_roles_are_seeded_when_team_is_created(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $this->assertDatabaseHas('team_roles', [
            'team_id' => $team->id,
            'key' => RolePresets::ACCOUNTANT,
            'is_system' => true,
        ]);
        $this->assertDatabaseHas('team_roles', [
            'team_id' => $team->id,
            'key' => RolePresets::VIEWER,
            'is_system' => true,
        ]);
    }

    public function test_viewer_cannot_create_vehicle_or_trip(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('vehicles.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('vehicles.trips.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('vehicles.trips.import.create'))
            ->assertForbidden();

        $vehicle = Vehicle::factory()->for($owner->currentTeam)->create();
        $trip = Trip::factory()->forVehicle($vehicle)->create();

        $this->actingAs($viewer)
            ->post(route('vehicles.trips.toggle-purpose', $trip))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('vehicles.store'), [
                'name' => 'Blocked',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_viewer_can_view_trip_log(): void
    {
        [, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('vehicles.trips.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('vehicles.index'))
            ->assertOk();
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
