<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTeamMemberRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_roles_can_be_updated(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $user->currentTeam->users()->attach(
            $otherUser = User::factory()->create(), ['role' => 'accountant']
        );

        $this->put('/teams/'.$user->currentTeam->id.'/members/'.$otherUser->id, [
            'role' => 'viewer',
        ]);

        $this->assertTrue($otherUser->fresh()->hasTeamRole(
            $user->currentTeam->fresh(), 'viewer'
        ));
    }

    public function test_only_team_owner_can_update_team_member_roles(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $user->currentTeam->users()->attach(
            $otherUser = User::factory()->create(), ['role' => 'accountant']
        );

        $this->actingAs($otherUser);

        $this->put('/teams/'.$user->currentTeam->id.'/members/'.$otherUser->id, [
            'role' => 'viewer',
        ]);

        $this->assertTrue($otherUser->fresh()->hasTeamRole(
            $user->currentTeam->fresh(), 'accountant'
        ));
    }
}
