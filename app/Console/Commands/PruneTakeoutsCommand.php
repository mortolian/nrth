<?php

namespace App\Console\Commands;

use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Models\TakeoutRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneTakeoutsCommand extends Command
{
    protected $signature = 'takeouts:prune';

    protected $description = 'Delete expired takeout zip files and mark runs as expired';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $pruned = 0;

        TakeoutRun::queryWithoutTeamScope()
            ->where('status', TakeoutRunStatus::Ready)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (TakeoutRun $run) use ($disk, &$pruned): void {
                $path = (string) $run->storage_path;
                if ($path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }

                $run->forceFill([
                    'status' => TakeoutRunStatus::Expired,
                    'storage_path' => null,
                ])->save();

                $pruned++;
            });

        $this->info("Pruned {$pruned} expired takeout(s).");

        return self::SUCCESS;
    }
}
