<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Exceptions\IncompleteInstanceBackup;
use ZipArchive;

class InstanceBackupArchiveVerifier
{
    public function __construct(
        private readonly InstanceBackupStorageCatalog $catalog,
    ) {}

    public function assertComplete(string $zipPath): void
    {
        if (! is_file($zipPath)) {
            throw new IncompleteInstanceBackup("Backup zip [{$zipPath}] was not found, so uploaded files could not be verified.");
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new IncompleteInstanceBackup("Backup zip [{$zipPath}] could not be opened (ZipArchive {$opened}). Uploaded files were not verified.");
        }

        $password = config('backup.backup.password');
        if (is_string($password) && $password !== '') {
            $zip->setPassword($password);
        }

        $problems = [];
        foreach ($this->catalog->files() as $path) {
            $expected = filesize($path);
            if ($expected === false) {
                $problems[] = $path.' (unreadable after the zip was written)';

                continue;
            }

            $stat = null;
            foreach ($this->catalog->zipEntryNames($path) as $name) {
                $candidate = $zip->statName($name);
                if (is_array($candidate)) {
                    $stat = $candidate;
                    break;
                }
            }

            if ($stat === null) {
                $problems[] = $path;

                continue;
            }

            if ((int) $stat['size'] !== (int) $expected) {
                $problems[] = $path.' (zip has '.$stat['size'].' bytes, disk has '.$expected.')';
            }
        }

        $zip->close();

        if ($problems === []) {
            return;
        }

        $shown = array_slice($problems, 0, 20);
        $extra = count($problems) - count($shown);
        $message = 'Instance backup is missing '.count($problems).' storage file(s): '.implode(', ', $shown);
        if ($extra > 0) {
            $message .= " and {$extra} more";
        }
        $message .= '. The zip was discarded so it cannot be restored as a complete backup.';

        throw new IncompleteInstanceBackup($message);
    }
}
