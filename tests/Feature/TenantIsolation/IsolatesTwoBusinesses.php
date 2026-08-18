<?php

namespace Tests\Feature\TenantIsolation;

use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait IsolatesTwoBusinesses
{
    private User $ownerA;

    private Team $teamA;

    private User $ownerB;

    private Team $teamB;

    private function setUpTwoBusinesses(): void
    {
        $this->ownerA = User::factory()->withPersonalTeam()->create();
        $this->teamA = $this->ownerA->currentTeam;
        $this->ownerB = User::factory()->withPersonalTeam()->create();
        $this->teamB = $this->ownerB->currentTeam;

        $this->assertNotNull($this->teamA);
        $this->assertNotNull($this->teamB);
        $this->assertNotSame($this->teamA->id, $this->teamB->id);
    }

    private function actingAsBusiness(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    private function asOutsider(): void
    {
        $this->actingAsBusiness($this->ownerB, $this->teamB);
    }

    private function assertHiddenFromOtherTeam(TestResponse $response): void
    {
        $this->assertContains(
            $response->status(),
            [403, 404],
            'Expected 403 or 404 for another business’s record, got '.$response->status(),
        );
    }
}
