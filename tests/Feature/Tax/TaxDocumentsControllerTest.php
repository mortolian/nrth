<?php

namespace Tests\Feature\Tax;

use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Notifications\TakeoutReady;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaxDocumentsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTeamOwner(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_owner_can_view_tax_documents_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $response = $this->get(route('tax.documents.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Tax/Documents/Index')
            ->has('period')
            ->has('preview')
            ->has('recent_takeouts')
            ->where('can_generate_takeout', true));
    }

    public function test_non_owner_cannot_view_tax_documents_page(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);
        $this->actingAsTeamOwner($member, $team);

        $response = $this->get(route('tax.documents.index'));

        $response->assertForbidden();
    }

    public function test_job_notifies_owner_when_takeout_is_ready(): void
    {
        Notification::fake();
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $run = TakeoutRun::factory()->for($team)->create([
            'requested_by' => $user->id,
            'from_date' => now()->startOfYear()->toDateString(),
            'to_date' => now()->endOfYear()->toDateString(),
        ]);

        (new GenerateTakeoutJob($run->id))->handle(app(\App\Domain\Takeout\Services\TakeoutBuilder::class));

        Notification::assertSentTo($user, TakeoutReady::class);
    }
}
