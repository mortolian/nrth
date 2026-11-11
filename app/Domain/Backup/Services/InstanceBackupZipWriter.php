<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Exceptions\IncompleteInstanceBackup;
use Spatie\Backup\Enums\Encryption;
use Throwable;
use ZipArchive;

class InstanceBackupZipWriter
{
    /**
     * @param  list<InstanceBackupZipEntry>  $entries
     */
    public function add(string $zipPath, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new IncompleteInstanceBackup("Could not open the backup zip to add readable file copies (ZipArchive {$opened}).");
        }

        $password = config('backup.backup.password');
        $encryption = Encryption::tryFrom((string) config('backup.backup.encryption', 'default')) ?? Encryption::Default;
        $encrypt = is_string($password) && $password !== '' && $encryption->shouldEncrypt();
        if ($encrypt) {
            $zip->setPassword($password);
        }

        $start = $zip->numFiles;

        try {
            foreach ($entries as $entry) {
                $added = $entry->contents !== null
                    ? $zip->addFromString($entry->name, $entry->contents)
                    : $zip->addFile((string) $entry->source, $entry->name);

                if ($added !== true) {
                    throw new IncompleteInstanceBackup('Could not add '.$entry->name.' to the backup zip.');
                }
            }

            if ($encrypt) {
                $algorithm = $encryption->algorithm();
                if ($algorithm !== null) {
                    for ($index = $start; $index < $zip->numFiles; $index++) {
                        if (! $zip->setEncryptionIndex($index, $algorithm)) {
                            throw new IncompleteInstanceBackup('Could not encrypt the readable file copies in the backup zip.');
                        }
                    }
                }
            }

            $closed = $zip->close();
        } catch (Throwable $failed) {
            $zip->close();

            throw $failed;
        }

        if ($closed !== true) {
            throw new IncompleteInstanceBackup('Could not save the readable file copies into the backup zip.');
        }
    }
}
