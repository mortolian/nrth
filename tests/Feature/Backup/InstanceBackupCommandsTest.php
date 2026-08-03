<?php

namespace Tests\Feature\Backup;

use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Models\InstanceBackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_backup_rotate_command_runs(): void
    {
        $this->artisan('nrth:backup-rotate')
            ->assertSuccessful();
    }
}
