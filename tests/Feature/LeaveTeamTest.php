<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_leave_teams(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($user->currentTeam);

        $user->currentTeam->users()->attach(
            $otherUser = User::factory()->withPersonalTeam()->create(),
            ['role' => RolePresets::ACCOUNTANT]
        );
        $otherUser->forceFill(['current_team_id' => $user->currentTeam->id])->save();

        $this->actingAs($otherUser);

        $this->delete('/teams/'.$user->currentTeam->id.'/members/'.$otherUser->id)
            ->assertRedirect(config('fortify.home'));

        $this->assertFalse($otherUser->fresh()->belongsToTeam($user->currentTeam->fresh()));
        $this->assertNotNull($otherUser->fresh()->current_team_id);
        $this->assertTrue($otherUser->fresh()->ownsTeam($otherUser->fresh()->currentTeam));
    }

    public function test_team_owners_cant_leave_their_own_team(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $response = $this->delete('/teams/'.$user->currentTeam->id.'/members/'.$user->id);

        $response->assertSessionHasErrorsIn('removeTeamMember', ['team']);

        $this->assertNotNull($user->currentTeam->fresh());
    }

    public function test_invite_only_member_leaving_last_business_is_sent_to_create_business(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);

        $member = User::factory()->create([
            'completed_onboarding_at' => now(),
        ]);
        $owner->currentTeam->users()->attach($member, ['role' => RolePresets::VIEWER]);
        $member->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->actingAs($member->fresh())
            ->delete('/teams/'.$owner->currentTeam->id.'/members/'.$member->id)
            ->assertRedirect(route('teams.create'));

        $this->assertNull($member->fresh()->current_team_id);
        $this->assertFalse($member->fresh()->belongsToTeam($owner->currentTeam->fresh()));
    }

    public function test_viewer_sees_can_leave_current_team_on_profile(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);

        $viewer = User::factory()->create(['completed_onboarding_at' => now()]);
        $owner->currentTeam->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->actingAs($viewer->fresh())
            ->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_leave_current_team', true)
                ->where('current_team_role.key', RolePresets::VIEWER)
                ->where('current_team_role.label', 'Viewer'));
    }
}
