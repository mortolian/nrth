<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Exceptions\IncompleteInstanceBackup;
use ZipArchive;

/**
 * Moves the application tree Spatie writes at the zip root into instance_backup/.
 * README.md and takeout_backup/ stay at the root.
 */
class InstanceBackupZipLayout
{
    public function moveProjectTree(string $zipPath): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new IncompleteInstanceBackup("Could not open the backup zip to move the application files into instance_backup (ZipArchive {$opened}).");
        }

        $password = config('backup.backup.password');
        if (is_string($password) && $password !== '') {
            $zip->setPassword($password);
        }

        try {
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }

            $alreadyMoved = $this->projectTreeIsInsideInstanceBackup($names);
            usort($names, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

            foreach ($names as $name) {
                if ($this->shouldStayAtRoot($name, $alreadyMoved)) {
                    continue;
                }

                $target = InstanceBackupReadablePath::INSTANCE_ROOT.'/'.ltrim($name, '/');
                if ($zip->locateName($target) !== false) {
                    continue;
                }

                if (! $zip->renameName($name, $target)) {
                    throw new IncompleteInstanceBackup('Could not move '.$name.' into instance_backup.');
                }
            }

            $closed = $zip->close();
        } catch (IncompleteInstanceBackup $failed) {
            $zip->close();

            throw $failed;
        }

        if ($closed !== true) {
            throw new IncompleteInstanceBackup('Could not save the backup zip after moving the application files into instance_backup.');
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function projectTreeIsInsideInstanceBackup(array $names): bool
    {
        foreach ($names as $name) {
            if ($name === 'instance_backup/artisan' || $name === 'instance_backup/composer.json') {
                return true;
            }
        }

        return false;
    }

    private function shouldStayAtRoot(string $name, bool $alreadyMoved): bool
    {
        if (str_starts_with($name, 'takeout_backup/') || str_starts_with($name, 'instance_backup/')) {
            return true;
        }

        return $alreadyMoved && ($name === 'README.md' || $name === 'README.md/');
    }
}
