<?php

namespace Tests\Feature\Tax;

use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Notifications\TakeoutReady;
use App\Domain\Takeout\Services\TakeoutBuilder;
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

    public function test_tax_documents_redirects_to_backups_exports_hub(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $response = $this->get(route('tax.documents.index'));

        $response->assertRedirect(route('backups-exports.index', [
            'section' => 'takeout',
        ]));
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

        (new GenerateTakeoutJob($run->id))->handle(app(TakeoutBuilder::class));

        Notification::assertSentTo($user, TakeoutReady::class);
    }
}
