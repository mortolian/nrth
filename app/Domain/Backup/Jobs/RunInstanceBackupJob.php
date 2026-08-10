<?php

namespace App\Domain\Backup\Jobs;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Instance\Services\InstanceMailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Notifications\EventHandler as BackupEventHandler;
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
            'mirror_warning' => null,
        ])->save();

        $backups->markRunning();
        $backups->clearLastError();

        // Ensure instance SMTP From is applied to Spatie backup mail before backup:run
        // (Horizon workers can otherwise keep boot-time MAIL_FROM_ADDRESS=…@example.com).
        try {
            app(InstanceMailSettings::class)->applyToRuntime();
        } catch (Throwable) {
            //
        }

        $filenamesBefore = $backups->backupFilenames();
        $backupName = (string) config('backup.backup.name', config('app.name', 'nrth'));

        try {
            // Mail transport errors must not fail the zip. Spatie's default handler aborts
            // backup:run when status mail is rejected (e.g. unverified From domain).
            $exitCode = Artisan::call('backup:run', ['--disable-notifications' => true]);
            BackupEventHandler::enable();
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
                $this->notifyBackupFinished(success: false, backupName: $backupName, failure: new RuntimeException($message));

                throw new RuntimeException($message);
            }

            $created = array_values(array_diff($backups->backupFilenames(), $filenamesBefore));
            $listed = $backups->listLocalBackups();
            $match = null;

            if ($created !== []) {
                $createdName = $created[0];
                $match = collect($listed)->firstWhere('filename', $createdName);
            }

            $match ??= $listed[0] ?? null;

            if ($match === null) {
                $message = 'Backup command finished but no backup zip was found on the local disk.';
                $this->markFailed($run, $backups, $message);
                $this->notifyBackupFinished(success: false, backupName: $backupName, failure: new RuntimeException($message));

                throw new RuntimeException($message);
            }

            InstanceBackupRun::query()
                ->where('filename', $match['filename'])
                ->where('id', '!=', $run->id)
                ->delete();

            $run->forceFill([
                'status' => InstanceBackupRunStatus::Ready,
                'filename' => $match['filename'],
                'disk' => 'local',
                'storage_path' => $match['path'],
                'file_size_bytes' => $match['size_bytes'],
                'error_message' => null,
                'mirror_warning' => $backups->mirrorWarningFor($match['filename']),
                'completed_at' => ! empty($match['date'])
                    ? Carbon::parse($match['date'])
                    : now(),
            ])->save();

            $this->notifyBackupFinished(success: true, backupName: $backupName);
        } catch (Throwable $e) {
            BackupEventHandler::enable();

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
            BackupEventHandler::enable();
            $backups->markFinished();
        }
    }

    public function failed(?Throwable $e): void
    {
        BackupEventHandler::enable();

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

    private function notifyBackupFinished(bool $success, string $backupName, ?Throwable $failure = null): void
    {
        BackupEventHandler::enable();

        try {
            if ($success) {
                event(new BackupWasSuccessful('local', $backupName));

                return;
            }

            event(new BackupHasFailed(
                $failure instanceof \Exception
                    ? $failure
                    : new RuntimeException($failure?->getMessage() ?: 'Instance backup failed.'),
            ));
        } catch (Throwable $e) {
            Log::warning('Instance backup status notification could not be sent.', [
                'message' => $e->getMessage(),
            ]);
        }
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
