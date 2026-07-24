<?php

namespace App\Domain\Backup\Jobs;

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
        public readonly ?int $requestedByUserId = null,
    ) {
        $this->onQueue('long');
    }

    public function handle(InstanceBackupService $backups): void
    {
        $backups->markRunning();
        $backups->clearLastError();

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
                    'requested_by' => $this->requestedByUserId,
                ]);

                $backups->recordFailure($message);

                throw new RuntimeException($message);
            }
        } catch (Throwable $e) {
            if (! $backups->lastError()) {
                $backups->recordFailure($e->getMessage());
            }

            Log::error('Instance backup job exception.', [
                'message' => $e->getMessage(),
                'requested_by' => $this->requestedByUserId,
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

        if ($e !== null && ! $backups->lastError()) {
            $backups->recordFailure($e->getMessage() ?: 'Instance backup failed or timed out.');
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
