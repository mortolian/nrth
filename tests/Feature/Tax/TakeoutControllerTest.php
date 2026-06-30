<?php

namespace Tests\Feature\Tax;

use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TakeoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function actingAsTeamOwner(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_owner_can_queue_takeout(): void
    {
        Queue::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $response = $this->post(route('tax.takeouts.store'), [
            'from_date' => '2026-03-01',
            'to_date' => '2027-02-28',
        ]);

        $response->assertRedirect(route('tax.documents.index', [
            'from' => '2026-03-01',
            'to' => '2027-02-28',
        ]));

        $this->assertDatabaseHas('takeout_runs', [
            'team_id' => $team->id,
            'requested_by' => $user->id,
            'status' => TakeoutRunStatus::Queued->value,
        ]);

        Queue::assertPushed(GenerateTakeoutJob::class, fn (GenerateTakeoutJob $job): bool => $job->takeoutRunId > 0);
    }

    public function test_non_owner_cannot_queue_takeout(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);
        $this->actingAsTeamOwner($member, $team);

        $response = $this->post(route('tax.takeouts.store'), [
            'from_date' => '2026-03-01',
            'to_date' => '2027-02-28',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('takeout_runs', 0);
    }

    public function test_owner_can_download_ready_takeout(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $path = 'takeouts/test-download.zip';
        Storage::disk('local')->put($path, 'zip-contents');

        $run = TakeoutRun::factory()->for($team)->ready()->create([
            'requested_by' => $user->id,
            'storage_path' => $path,
            'from_date' => '2026-03-01',
            'to_date' => '2027-02-28',
        ]);

        $response = $this->get(route('tax.takeouts.download', $run));

        $response->assertOk();
        $response->assertDownload('nrth-takeout_2026-03-01_to_2027-02-28.zip');
    }

    public function test_download_rejects_expired_takeout(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $path = 'takeouts/expired.zip';
        Storage::disk('local')->put($path, 'zip-contents');

        $run = TakeoutRun::factory()->for($team)->ready()->create([
            'requested_by' => $user->id,
            'storage_path' => $path,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->get(route('tax.takeouts.download', $run));

        $response->assertStatus(410);
    }

    public function test_owner_cannot_download_other_teams_takeout(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $otherTeam = Team::factory()->create();
        $path = 'takeouts/other.zip';
        Storage::disk('local')->put($path, 'zip-contents');

        $run = TakeoutRun::factory()->for($otherTeam)->ready()->create([
            'storage_path' => $path,
        ]);

        $response = $this->get(route('tax.takeouts.download', $run));

        $response->assertNotFound();
    }
}
