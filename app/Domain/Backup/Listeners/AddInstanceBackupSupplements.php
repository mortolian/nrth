<?php

namespace App\Domain\Backup\Listeners;

use App\Domain\Backup\Services\InstanceBackupSupplement;
use App\Domain\Backup\Services\InstanceBackupZipWriter;
use Spatie\Backup\Events\BackupZipWasCreated;

/**
 * Adds instance_backup (raw files) and takeout_backup (readable names) before
 * the zip is copied to local and offsite disks.
 */
class AddInstanceBackupSupplements
{
    public function __construct(
        private readonly InstanceBackupSupplement $supplement,
        private readonly InstanceBackupZipWriter $writer,
    ) {}

    public function handle(BackupZipWasCreated $event): void
    {
        $this->writer->add($event->pathToZip, $this->supplement->entries(basename($event->pathToZip)));
    }
}
