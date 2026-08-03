<?php

namespace App\Console\Commands;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Backup\Services\InstanceBackupTypeResolver;
use Illuminate\Console\Command;

class RunInstanceBackupCommand extends Command
{
    protected $signature = 'nrth:backup-run';

    protected $description = 'Create a typed instance backup run and queue Spatie backup:run.';

    public function handle(
        InstanceBackupService $backups,
        InstanceBackupTypeResolver $types,
    ): int {
        $backups->failStaleActiveRuns();

        if ($backups->hasActiveRun()) {
            $this->components->warn('A backup is already queued or running; skipping.');

            return self::SUCCESS;
        }

        $resolved = $types->typesFor(now());

        $run = InstanceBackupRun::query()->create([
            'requested_by' => null,
            'status' => InstanceBackupRunStatus::Queued,
            'types' => $resolved,
        ]);

        RunInstanceBackupJob::dispatch($run->id);

        $this->components->info('Queued instance backup #'.$run->id.' ('.implode(', ', $resolved).').');

        return self::SUCCESS;
    }
}
