<?php

namespace App\Domain\Backup\Listeners;

use App\Domain\Backup\Services\InstanceBackupSupplement;
use App\Domain\Backup\Services\InstanceBackupZipLayout;
use App\Domain\Backup\Services\InstanceBackupZipWriter;
use Spatie\Backup\Events\BackupZipWasCreated;

/**
 * Moves the application tree into instance_backup/ and adds takeout_backup/
 * before the zip is copied to local and offsite disks.
 */
class AddInstanceBackupSupplements
{
    public function __construct(
        private readonly InstanceBackupZipLayout $layout,
        private readonly InstanceBackupSupplement $supplement,
        private readonly InstanceBackupZipWriter $writer,
    ) {}

    public function handle(BackupZipWasCreated $event): void
    {
        $this->layout->moveProjectTree($event->pathToZip);
        $this->writer->add($event->pathToZip, $this->supplement->entries(basename($event->pathToZip)));
    }
}
