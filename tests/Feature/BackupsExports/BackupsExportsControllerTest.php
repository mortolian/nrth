<?php

namespace Tests\Feature\BackupsExports;

use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupsExportsControllerTest extends TestCase
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

    public function test_owner_can_view_takeout_section_without_backup_section(): void
    {
        Config::set('nrth.operator_emails', []);

        $first = User::factory()->withPersonalTeam()->create([
            'email' => 'first@example.com',
        ]);
        // Create a second owner-of-their-own-team who is not an instance operator.
        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'owner@example.com',
            'is_instance_operator' => false,
        ]);
        $user->forceFill(['is_instance_operator' => false])->save();
        $this->assertTrue($first->fresh()->is_instance_operator);

        $this->actingAsTeamOwner($user->fresh(), $user->currentTeam);

        $response = $this->get(route('backups-exports.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackupsExports/Index')
            ->where('can_generate_takeout', true)
            ->where('can_manage_backups', false)
            ->where('section', 'takeout')
            ->has('period')
            ->has('recent_takeouts'));
    }

    public function test_operator_sees_backup_section(): void
    {
        Config::set('nrth.operator_emails', []);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $response = $this->get(route('backups-exports.index', ['section' => 'backup']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('can_manage_backups', true)
            ->where('section', 'backup')
            ->has('backups')
            ->has('backup_schedule_hint'));
    }

    public function test_non_owner_non_operator_forbidden(): void
    {
        Config::set('nrth.operator_emails', []);

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);
        $this->actingAsTeamOwner($member, $owner->currentTeam);

        $this->get(route('backups-exports.index'))->assertForbidden();
    }

    public function test_tax_documents_redirects_to_hub(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $response = $this->get(route('tax.documents.index', [
            'from' => '2026-03-01',
            'to' => '2027-02-28',
        ]));

        $response->assertRedirect(route('backups-exports.index', [
            'section' => 'takeout',
            'from' => '2026-03-01',
            'to' => '2027-02-28',
        ]));
    }

    public function test_non_operator_cannot_queue_backup(): void
    {
        Config::set('nrth.operator_emails', []);
        Queue::fake();

        User::factory()->withPersonalTeam()->create(['email' => 'first@example.com']);
        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'owner@example.com',
            'is_instance_operator' => false,
        ]);
        $user->forceFill(['is_instance_operator' => false])->save();
        $this->actingAsTeamOwner($user->fresh(), $user->currentTeam);

        $this->post(route('backups-exports.backups.store'))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_operator_can_queue_backup(): void
    {
        Queue::fake();
        Config::set('nrth.operator_emails', []);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $response = $this->post(route('backups-exports.backups.store'));

        $response->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
        Queue::assertPushed(RunInstanceBackupJob::class, function (RunInstanceBackupJob $job): bool {
            return $job->queue === 'long';
        });
        $this->assertFalse(app(InstanceBackupService::class)->isRunning());
        $this->assertNull(app(InstanceBackupService::class)->lastError());
    }

    public function test_backup_job_records_failure_when_command_exits_nonzero(): void
    {
        $service = app(InstanceBackupService::class);
        $service->clearLastError();

        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run')
            ->andReturn(1);
        \Illuminate\Support\Facades\Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Backup failed because: pg_dump: command not found\n");

        try {
            (new RunInstanceBackupJob(1))->handle($service);
            $this->fail('Expected backup job to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Backup failed', $e->getMessage());
        }

        $this->assertFalse($service->isRunning());
        $this->assertNotNull($service->lastError());
        $this->assertStringContainsString('Backup failed', (string) $service->lastError());
    }

    public function test_operator_cannot_download_invalid_backup_filename(): void
    {
        Config::set('nrth.operator_emails', []);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $this->get(route('backups-exports.backups.download', ['filename' => '../etc/passwd.zip']))
            ->assertNotFound();
    }

    public function test_operator_can_download_backup_file(): void
    {
        Config::set('nrth.operator_emails', []);
        Config::set('backup.backup.name', 'nrth');
        Config::set('backup.backup.destination.disks', ['local']);

        Storage::fake('local');
        $relative = 'nrth/2026-07-24-22-00-00.zip';
        Storage::disk('local')->put($relative, 'zip-bytes');

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $response = $this->get(route('backups-exports.backups.download', [
            'filename' => '2026-07-24-22-00-00.zip',
        ]));

        $response->assertOk();
        $response->assertDownload('2026-07-24-22-00-00.zip');
    }

    public function test_owner_can_delete_takeout(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $path = 'takeouts/delete-me.zip';
        Storage::disk('local')->put($path, 'zip');

        $run = TakeoutRun::factory()->for($team)->ready()->create([
            'requested_by' => $user->id,
            'storage_path' => $path,
        ]);

        $response = $this->delete(route('tax.takeouts.destroy', $run));

        $response->assertRedirect();
        $this->assertDatabaseMissing('takeout_runs', ['id' => $run->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_owner_can_retry_failed_takeout(): void
    {
        Queue::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingAsTeamOwner($user, $team);

        $run = TakeoutRun::factory()->for($team)->failed()->create([
            'requested_by' => $user->id,
            'from_date' => '2026-03-01',
            'to_date' => '2027-02-28',
        ]);

        $response = $this->post(route('tax.takeouts.retry', $run));

        $response->assertRedirect(route('backups-exports.index', [
            'section' => 'takeout',
            'from' => '2026-03-01',
            'to' => '2027-02-28',
        ]));

        $run->refresh();
        $this->assertSame(TakeoutRunStatus::Queued, $run->status);
        Queue::assertPushed(GenerateTakeoutJob::class, fn (GenerateTakeoutJob $job): bool => $job->queue === 'long');
    }
}
