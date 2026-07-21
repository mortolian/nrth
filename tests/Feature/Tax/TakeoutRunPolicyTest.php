<?php

namespace Tests\Feature\Tax;

use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TakeoutRunPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_owner_can_create_and_download_takeout(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $this->assertTrue($owner->can('create', TakeoutRun::class));

        $run = TakeoutRun::factory()->for($team)->ready()->create([
            'requested_by' => $owner->id,
        ]);

        $this->assertTrue($owner->can('view', $run));
        $this->assertTrue($owner->can('download', $run));
    }

    public function test_non_owner_team_member_cannot_create_takeout(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->assertFalse($member->can('create', TakeoutRun::class));
    }

    public function test_owner_cannot_download_other_teams_takeout(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $otherTeam = Team::factory()->create();
        $otherOwner = User::factory()->create();
        $otherTeam->forceFill(['user_id' => $otherOwner->id])->save();

        $run = TakeoutRun::factory()->for($otherTeam)->ready()->create([
            'requested_by' => $otherOwner->id,
        ]);

        $owner->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->assertFalse($owner->can('download', $run));
    }

    public function test_takeout_run_is_downloadable_when_ready_and_not_expired(): void
    {
        $run = TakeoutRun::factory()->ready()->make();

        $this->assertTrue($run->isDownloadable());
    }

    public function test_takeout_run_is_not_downloadable_when_expired(): void
    {
        $run = TakeoutRun::factory()->ready()->make([
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($run->isDownloadable());
    }
}
