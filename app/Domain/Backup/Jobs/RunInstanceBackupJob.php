<?php

namespace App\Domain\Backup\Jobs;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunInstanceBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public int $backupRunId,
    ) {
        $this->onQueue('long');
    }

    public function handle(InstanceBackupService $backups): void
    {
        $run = InstanceBackupRun::query()->find($this->backupRunId);
        if ($run === null) {
            return;
        }

        if (! in_array($run->status, [InstanceBackupRunStatus::Queued, InstanceBackupRunStatus::Processing], true)) {
            return;
        }

        $run->forceFill([
            'status' => InstanceBackupRunStatus::Processing,
            'error_message' => null,
        ])->save();

        $backups->markRunning();
        $backups->clearLastError();

        $filenamesBefore = $backups->backupFilenames();

        try {
            $exitCode = Artisan::call('backup:run');
            $output = trim(Artisan::output());

            if ($exitCode !== 0) {
                $message = $output !== ''
                    ? $this->summarizeBackupOutput($output)
                    : "Instance backup command failed (exit code {$exitCode}).";

                Log::error('Instance backup command failed.', [
                    'exit_code' => $exitCode,
                    'output' => $output,
                    'backup_run_id' => $run->id,
                ]);

                $this->markFailed($run, $backups, $message);

                throw new RuntimeException($message);
            }

            $created = array_values(array_diff($backups->backupFilenames(), $filenamesBefore));
            $listed = $backups->listBackups();
            $match = null;

            if ($created !== []) {
                $createdName = $created[0];
                $match = collect($listed)->firstWhere('filename', $createdName);
            }

            $match ??= $listed[0] ?? null;

            if ($match === null) {
                $message = 'Backup command finished but no backup zip was found.';
                $this->markFailed($run, $backups, $message);

                throw new RuntimeException($message);
            }

            InstanceBackupRun::query()
                ->where('disk', $match['disk'])
                ->where('filename', $match['filename'])
                ->where('id', '!=', $run->id)
                ->delete();

            $run->forceFill([
                'status' => InstanceBackupRunStatus::Ready,
                'filename' => $match['filename'],
                'disk' => $match['disk'],
                'storage_path' => $match['path'],
                'file_size_bytes' => $match['size_bytes'],
                'error_message' => null,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $freshRun = $run->fresh();
            if ($freshRun !== null && $freshRun->status !== InstanceBackupRunStatus::Failed) {
                $this->markFailed($freshRun, $backups, $e->getMessage());
            }

            Log::error('Instance backup job exception.', [
                'message' => $e->getMessage(),
                'backup_run_id' => $run->id,
            ]);

            throw $e;
        } finally {
            $backups->markFinished();
        }
    }

    public function failed(?Throwable $e): void
    {
        $backups = app(InstanceBackupService::class);
        $backups->markFinished();

        $run = InstanceBackupRun::query()->find($this->backupRunId);
        if ($run === null) {
            return;
        }

        if (! in_array($run->status, [InstanceBackupRunStatus::Queued, InstanceBackupRunStatus::Processing], true)) {
            return;
        }

        $this->markFailed(
            $run,
            $backups,
            $e?->getMessage() ?: 'Instance backup failed or timed out.',
        );
    }

    private function markFailed(InstanceBackupRun $run, InstanceBackupService $backups, string $message): void
    {
        $backups->recordFailure($message);

        $run->forceFill([
            'status' => InstanceBackupRunStatus::Failed,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();
    }

    private function summarizeBackupOutput(string $output): string
    {
        $lines = preg_split('/\R/', $output) ?: [];
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line !== '' && str_contains(strtolower($line), 'backup failed')) {
                return $line;
            }
        }

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strlen($line) > 240 ? mb_substr($line, 0, 237).'…' : $line;
            }
        }

        return 'Instance backup command failed.';
    }
}
