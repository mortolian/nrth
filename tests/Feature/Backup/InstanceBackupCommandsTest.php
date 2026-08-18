<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\RunInstanceBackupCommand;
use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Backup\Services\InstanceBackupTypeResolver;
use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class InstanceBackupCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_run_command_queues_typed_run(): void
    {
        Queue::fake();

        $this->artisan('nrth:backup-run')
            ->assertSuccessful();

        $run = InstanceBackupRun::query()->first();
        $this->assertNotNull($run);
        $this->assertContains('daily', $run->typeList());
        Queue::assertPushed(RunInstanceBackupJob::class);
    }

    public function test_backup_run_skips_when_already_active(): void
    {
        Queue::fake();

        InstanceBackupRun::factory()->create([
            'status' => InstanceBackupRunStatus::Processing,
        ]);

        $this->artisan('nrth:backup-run')
            ->assertSuccessful();

        $this->assertSame(1, InstanceBackupRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_backup_run_wait_completes_in_process(): void
    {
        Queue::fake();

        $filename = '2026-08-18-16-00-00.zip';
        $service = \Mockery::mock(InstanceBackupService::class, [
            app(InstanceBackupRetentionSettings::class),
            app(InstanceBackupTypeResolver::class),
            app(InstanceBackupDestinationSettings::class),
        ])->makePartial();
        $service->shouldReceive('backupFilenames')->andReturn([], [$filename]);
        $service->shouldReceive('listLocalBackups')->andReturn([
            [
                'filename' => $filename,
                'path' => 'nrth/'.$filename,
                'disk' => 'local',
                'date' => now()->toIso8601String(),
                'size_bytes' => 2048,
            ],
        ]);
        $service->shouldReceive('mirrorWarningFor')->with($filename)->andReturn(null);
        $this->app->instance(InstanceBackupService::class, $service);

        Artisan::shouldReceive('call')->once()->with('backup:run', ['--disable-notifications' => true])->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('');

        $this->assertSame(0, $this->runBackupRunCommand(['--wait' => true]));

        Queue::assertNothingPushed();
        $run = InstanceBackupRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(InstanceBackupRunStatus::Ready, $run->status);
        $this->assertSame($filename, $run->filename);
        $this->assertSame(2048, $run->file_size_bytes);
    }

    public function test_backup_run_wait_fails_when_spatie_backup_exits_nonzero(): void
    {
        Queue::fake();

        Artisan::shouldReceive('call')->once()->with('backup:run', ['--disable-notifications' => true])->andReturn(1);
        Artisan::shouldReceive('output')->andReturn("Backup failed because: pg_dump missing\n");

        $this->assertSame(1, $this->runBackupRunCommand(['--wait' => true]));

        Queue::assertNothingPushed();
        $run = InstanceBackupRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(InstanceBackupRunStatus::Failed, $run->status);
        $this->assertNotNull($run->error_message);
    }

    public function test_backup_run_wait_does_not_skip_an_active_run(): void
    {
        Queue::fake();

        $existing = InstanceBackupRun::factory()->create([
            'status' => InstanceBackupRunStatus::Queued,
        ]);

        $this->artisan('nrth:backup-run', [
            '--wait' => true,
            '--wait-timeout' => 1,
        ])->assertFailed();

        Queue::assertNothingPushed();
        $this->assertSame(1, InstanceBackupRun::query()->count());
        $this->assertSame(InstanceBackupRunStatus::Queued, $existing->fresh()->status);
        $this->assertSame($existing->id, InstanceBackupRun::query()->value('id'));
    }

    public function test_backup_rotate_command_runs(): void
    {
        $this->artisan('nrth:backup-rotate')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runBackupRunCommand(array $parameters): int
    {
        $command = $this->app->make(RunInstanceBackupCommand::class);
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput($parameters), new BufferedOutput);
    }
}
