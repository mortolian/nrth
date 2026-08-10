<?php

namespace Tests\Feature\BackupsExports;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Backup\Services\InstanceBackupTypeResolver;
use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        // Avoid syncing real Sail/host backup zips into Ready runs during these tests.
        Config::set('backup.backup.name', 'nrth');
        Config::set('backup.backup.destination.disks', ['local']);
        Storage::fake('local');
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

        $manual = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => $user->id,
            'filename' => 'manual-2026-08-01.zip',
            'storage_path' => 'nrth/manual-2026-08-01.zip',
            'types' => ['daily'],
            'created_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
        ]);
        $scheduled = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => null,
            'filename' => 'scheduled-2026-06-24.zip',
            'storage_path' => 'nrth/scheduled-2026-06-24.zip',
            'types' => ['daily', 'weekly'],
            'created_at' => now()->subHours(1),
            'completed_at' => now()->subDays(40),
        ]);

        $response = $this->get(route('backups-exports.index', ['section' => 'backup']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('can_manage_backups', true)
            ->where('section', 'backup')
            ->has('recent_backups', 2)
            ->where('recent_backups.0.id', $manual->id)
            ->where('recent_backups.0.source', 'manual')
            ->where('recent_backups.0.types', ['daily'])
            ->where('recent_backups.1.id', $scheduled->id)
            ->where('recent_backups.1.source', 'scheduled')
            ->where('recent_backups.1.types', ['daily', 'weekly'])
            ->has('backup_links')
            ->has('backup_schedule_hint')
            ->missing('backup_retention')
            ->missing('restore_guide')
            ->missing('operators'));
    }

    public function test_operator_can_view_restore_page_with_selected_filename(): void
    {
        Config::set('nrth.operator_emails', []);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        InstanceBackupRun::factory()->ready()->create([
            'requested_by' => $user->id,
            'filename' => 'manual-2026-08-01.zip',
            'storage_path' => 'nrth/manual-2026-08-01.zip',
        ]);

        $this->get(route('backups-exports.restore', ['filename' => 'manual-2026-08-01.zip']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('BackupsExports/Restore')
                ->has('restore_guide')
                ->has('restore_guide.container_zip_dir')
                ->where('selected_filename', 'manual-2026-08-01.zip')
                ->where('ready_filenames', ['manual-2026-08-01.zip']));
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
        $this->assertDatabaseHas('instance_backup_runs', [
            'requested_by' => $user->id,
            'status' => InstanceBackupRunStatus::Queued->value,
        ]);
        $run = InstanceBackupRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertContains('daily', $run->typeList());
        Queue::assertPushed(RunInstanceBackupJob::class, function (RunInstanceBackupJob $job): bool {
            return $job->queue === 'long' && $job->backupRunId > 0;
        });
    }

    public function test_backup_job_marks_run_failed_when_command_exits_nonzero(): void
    {
        Config::set('nrth.operator_emails', []);
        $user = User::factory()->withPersonalTeam()->create(['is_instance_operator' => true]);
        $run = InstanceBackupRun::factory()->create([
            'requested_by' => $user->id,
            'status' => InstanceBackupRunStatus::Queued,
        ]);

        Artisan::shouldReceive('call')->once()->with('backup:run', ['--disable-notifications' => true])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn("Backup failed because: pg_dump missing\n");

        try {
            (new RunInstanceBackupJob($run->id))->handle(app(InstanceBackupService::class));
            $this->fail('Expected backup job to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Backup failed', $e->getMessage());
        }

        $run->refresh();
        $this->assertSame(InstanceBackupRunStatus::Failed, $run->status);
        $this->assertNotNull($run->error_message);
    }

    public function test_backup_job_does_not_record_failure_when_run_is_deleted_during_exception(): void
    {
        $run = InstanceBackupRun::factory()->create([
            'status' => InstanceBackupRunStatus::Queued,
        ]);

        $backups = \Mockery::mock(InstanceBackupService::class);
        $backups->shouldReceive('markRunning')->once();
        $backups->shouldReceive('clearLastError')->once();
        $backups->shouldReceive('backupFilenames')->once()->andReturn([]);
        $backups->shouldReceive('recordFailure')->never();
        $backups->shouldReceive('markFinished')->once();

        Artisan::shouldReceive('call')->once()->with('backup:run', ['--disable-notifications' => true])->andReturnUsing(function () use ($run): never {
            $run->delete();

            throw new \RuntimeException('Backup process crashed.');
        });
        Artisan::shouldReceive('output')->never();

        try {
            (new RunInstanceBackupJob($run->id))->handle($backups);
            $this->fail('Expected backup job to throw.');
        } catch (\RuntimeException) {
            $this->assertDatabaseMissing('instance_backup_runs', ['id' => $run->id]);
        }
    }

    public function test_operator_can_download_ready_backup_run(): void
    {
        Config::set('nrth.operator_emails', []);
        Config::set('backup.backup.name', 'nrth');
        Config::set('backup.backup.destination.disks', ['local']);
        Storage::fake('local');

        $filename = '2026-07-24-22-00-00.zip';
        Storage::disk('local')->put('nrth/'.$filename, 'zip-bytes');

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $run = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => $user->id,
            'filename' => $filename,
            'disk' => 'local',
            'storage_path' => 'nrth/'.$filename,
        ]);

        $response = $this->get(route('backups-exports.backups.download', $run));

        $response->assertOk();
        $response->assertDownload($filename);
    }

    public function test_operator_can_retry_failed_backup(): void
    {
        Queue::fake();
        Config::set('nrth.operator_emails', []);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'ops@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAsTeamOwner($user, $user->currentTeam);

        $run = InstanceBackupRun::factory()->failed()->create([
            'requested_by' => $user->id,
        ]);

        $response = $this->post(route('backups-exports.backups.retry', $run));

        $response->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
        $run->refresh();
        $this->assertSame(InstanceBackupRunStatus::Queued, $run->status);
        Queue::assertPushed(RunInstanceBackupJob::class);
    }

    public function test_sync_reclaims_orphan_zip_for_stuck_queued_run(): void
    {
        Config::set('backup.backup.name', 'nrth');
        Config::set('backup.backup.destination.disks', ['local']);
        Storage::fake('local');

        $filename = '2026-07-24-22-50-00.zip';
        Storage::disk('local')->put('nrth/'.$filename, 'zip-bytes');

        $run = InstanceBackupRun::factory()->create([
            'status' => InstanceBackupRunStatus::Queued,
            'filename' => null,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        // Spatie reads real filesystem dates; fake disk may not expose BackupDestination well.
        // Exercise reclaim directly with a partial mock of listBackups via subclassing service is heavy —
        // instead call reclaim with a real service after binding list via partial mock.
        $service = \Mockery::mock(InstanceBackupService::class, [
            app(InstanceBackupRetentionSettings::class),
            app(InstanceBackupTypeResolver::class),
            app(InstanceBackupDestinationSettings::class),
        ])->makePartial();
        $service->shouldReceive('listLocalBackups')->andReturn([
            [
                'filename' => $filename,
                'path' => 'nrth/'.$filename,
                'disk' => 'local',
                'date' => now()->subMinute()->toIso8601String(),
                'size_bytes' => 9,
            ],
        ]);

        $service->reclaimOrphanZipsForActiveRuns();

        $run->refresh();
        $this->assertSame(InstanceBackupRunStatus::Ready, $run->status);
        $this->assertSame($filename, $run->filename);
        $this->assertSame(9, $run->file_size_bytes);
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
