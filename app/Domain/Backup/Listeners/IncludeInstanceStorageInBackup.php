<?php

namespace App\Domain\Backup\Listeners;

use App\Domain\Backup\Services\InstanceBackupStorageCatalog;
use Spatie\Backup\Events\BackupManifestWasCreated;

/**
 * Spatie's project walk can miss uploaded files when `storage` is a symlink
 * or when the public disk only shows up as an empty `public/storage` link.
 * Add any required storage file that is not already in the manifest so the
 * zip is built with those bytes before it is copied to destinations.
 */
class IncludeInstanceStorageInBackup
{
    public function __construct(
        private readonly InstanceBackupStorageCatalog $catalog,
    ) {}

    public function handle(BackupManifestWasCreated $event): void
    {
        $present = [];
        foreach ($event->manifest->files() as $path) {
            $real = realpath($path);
            if (is_string($real)) {
                $present[$real] = true;
            }
        }

        $missing = [];
        foreach ($this->catalog->files() as $path) {
            $real = realpath($path);
            if (! is_string($real) || isset($present[$real])) {
                continue;
            }

            $missing[] = $path;
        }

        if ($missing !== []) {
            $event->manifest->addFiles($missing);
        }
    }
}
