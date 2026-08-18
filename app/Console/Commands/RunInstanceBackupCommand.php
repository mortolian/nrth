<?php

namespace App\Console\Commands;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Jobs\RunInstanceBackupJob;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Backup\Services\InstanceBackupTypeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

class RunInstanceBackupCommand extends Command
{
    protected $signature = 'nrth:backup-run
                            {--wait : Run the backup in this process and wait until it is ready or failed}
                            {--wait-timeout=3900 : Seconds to wait when a backup is already queued or running}';

    protected $description = 'Create a typed instance backup run. Queues Spatie backup:run, or runs it in-process with --wait.';

    public function handle(
        InstanceBackupService $backups,
        InstanceBackupTypeResolver $types,
    ): int {
        $backups->failStaleActiveRuns();

        $wait = (bool) $this->option('wait');
        $timeout = max(1, (int) $this->option('wait-timeout'));

        if ($backups->hasActiveRun()) {
            if (! $wait) {
                $this->components->warn('A backup is already queued or running; skipping.');

                return self::SUCCESS;
            }

            $run = InstanceBackupRun::query()
                ->whereIn('status', [
                    InstanceBackupRunStatus::Queued,
                    InstanceBackupRunStatus::Processing,
                ])
                ->latest('id')
                ->first();

            if ($run === null) {
                $this->components->error('A backup appeared active but no queued or processing run was found.');

                return self::FAILURE;
            }

            $this->components->warn('A backup is already queued or running; waiting for #'.$run->id.'.');

            return $this->waitForExistingRun($run, $timeout);
        }

        $resolved = $types->typesFor(now());

        $run = InstanceBackupRun::query()->create([
            'requested_by' => null,
            'status' => InstanceBackupRunStatus::Queued,
            'types' => $resolved,
        ]);

        if ($wait) {
            $this->components->info('Starting instance backup #'.$run->id.' ('.implode(', ', $resolved).').');

            return $this->runInProcess($run);
        }

        RunInstanceBackupJob::dispatch($run->id);

        $this->components->info('Queued instance backup #'.$run->id.' ('.implode(', ', $resolved).').');

        return self::SUCCESS;
    }

    private function runInProcess(InstanceBackupRun $run): int
    {
        $job = new RunInstanceBackupJob($run->id);

        try {
            $job->handle(app(InstanceBackupService::class));
        } catch (Throwable $e) {
            $run->refresh();
            $this->components->error($run->error_message ?: $e->getMessage());

            return self::FAILURE;
        }

        $run->refresh();

        return $this->reportFinishedRun($run);
    }

    private function waitForExistingRun(InstanceBackupRun $run, int $timeoutSeconds): int
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $run->refresh();

            if ($run->status === InstanceBackupRunStatus::Ready) {
                return $this->reportFinishedRun($run);
            }

            if ($run->status === InstanceBackupRunStatus::Failed) {
                $this->components->error($run->error_message ?: 'Instance backup failed.');

                return self::FAILURE;
            }

            sleep(1);
        }

        $this->components->error(
            'Timed out waiting for instance backup #'.$run->id.'. Check Horizon and Settings → Backups & exports.',
        );

        return self::FAILURE;
    }

    private function reportFinishedRun(InstanceBackupRun $run): int
    {
        if ($run->status === InstanceBackupRunStatus::Ready) {
            $this->components->info('Instance backup ready: '.$this->describeReadyRun($run));

            return self::SUCCESS;
        }

        $this->components->error($run->error_message ?: 'Instance backup failed.');

        return self::FAILURE;
    }

    private function describeReadyRun(InstanceBackupRun $run): string
    {
        $name = filled($run->filename) ? (string) $run->filename : 'zip created';
        $bytes = $run->file_size_bytes;

        if (! is_int($bytes) || $bytes < 1) {
            return $name;
        }

        return $name.' ('.Number::fileSize($bytes).')';
    }
}
