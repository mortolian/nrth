<?php

namespace App\Console\Commands;

use App\Domain\Backup\Services\InstanceBackupService;
use Illuminate\Console\Command;

class RotateInstanceBackupsCommand extends Command
{
    protected $signature = 'nrth:backup-rotate';

    protected $description = 'Rotate instance backups by typed retention counts.';

    public function handle(InstanceBackupService $backups): int
    {
        $result = $backups->rotateByTypeCounts();

        $this->components->info(sprintf(
            'Rotation complete: %d protected, %d deleted.',
            $result['protected'],
            $result['deleted'],
        ));

        return self::SUCCESS;
    }
}
