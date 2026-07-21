<?php

namespace App\Domain\Backup\Jobs;

use App\Domain\Backup\Services\InstanceBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunInstanceBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly ?int $requestedByUserId = null,
    ) {}

    public function handle(InstanceBackupService $backups): void
    {
        $backups->markRunning();

        try {
            $exitCode = Artisan::call('backup:run');

            if ($exitCode !== 0) {
                Log::error('Instance backup command failed.', [
                    'exit_code' => $exitCode,
                    'output' => Artisan::output(),
                    'requested_by' => $this->requestedByUserId,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Instance backup job exception.', [
                'message' => $e->getMessage(),
                'requested_by' => $this->requestedByUserId,
            ]);

            throw $e;
        } finally {
            $backups->markFinished();
        }
    }
}
