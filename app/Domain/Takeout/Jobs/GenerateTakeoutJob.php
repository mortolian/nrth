<?php

namespace App\Domain\Takeout\Jobs;

use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Domain\Takeout\Notifications\TakeoutFailed;
use App\Domain\Takeout\Notifications\TakeoutReady;
use App\Domain\Takeout\Services\TakeoutBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateTakeoutJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public int $takeoutRunId,
    ) {}

    public function handle(TakeoutBuilder $builder): void
    {
        $run = TakeoutRun::queryWithoutTeamScope()->find($this->takeoutRunId);
        if ($run === null) {
            return;
        }

        if (! in_array($run->status, [TakeoutRunStatus::Queued, TakeoutRunStatus::Processing], true)) {
            return;
        }

        $run->forceFill(['status' => TakeoutRunStatus::Processing])->save();

        try {
            $result = $builder->build($run);

            $run->forceFill([
                'status' => TakeoutRunStatus::Ready,
                'storage_path' => $result['storage_path'],
                'file_size_bytes' => $result['file_size_bytes'],
                'manifest' => $result['manifest'],
                'error_message' => null,
                'completed_at' => now(),
                'expires_at' => now()->addDays(7),
            ])->save();

            $run->loadMissing('requestedBy');
            $run->requestedBy?->notify(new TakeoutReady($run));
        } catch (Throwable $e) {
            Log::error('Takeout generation failed', [
                'takeout_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            $run->forceFill([
                'status' => TakeoutRunStatus::Failed,
                'error_message' => $e->getMessage(),
            ])->save();

            $run->loadMissing('requestedBy');
            $run->requestedBy?->notify(new TakeoutFailed($run, $e->getMessage()));

            throw $e;
        }
    }
}
