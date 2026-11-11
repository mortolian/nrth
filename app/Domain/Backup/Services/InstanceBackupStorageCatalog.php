<?php

namespace App\Domain\Backup\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use UnexpectedValueException;

/**
 * Every user file that an instance backup must contain.
 *
 * Runtime cache (`storage/framework`, `storage/logs`) and the backup zip
 * directory itself are omitted. Receipts, invoice PDFs, logos, bank uploads,
 * and takeouts live under `storage/app` and are always required.
 */
class InstanceBackupStorageCatalog
{
    public function __construct(
        private readonly ?string $storageRoot = null,
        private readonly ?string $backupName = null,
    ) {}

    public function storageRoot(): string
    {
        $root = $this->storageRoot ?? storage_path();

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<string> Absolute paths, sorted.
     */
    public function files(): array
    {
        $root = $this->storageRoot();
        if (! is_dir($root)) {
            throw new RuntimeException("Storage directory [{$root}] does not exist. The backup would not contain uploaded files.");
        }

        $destination = $this->backupDestinationRealPath();
        $files = [];
        $seen = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::FOLLOW_SYMLINKS,
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException("Cannot read storage directory [{$root}]. The backup would not contain uploaded files.", 0, $e);
        }

        try {
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                $real = realpath($path);
                if ($real === false) {
                    throw new RuntimeException("Cannot read storage file [{$path}]. The backup would not contain uploaded files.");
                }

                if (isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;

                if ($destination !== null && ($real === $destination || str_starts_with($real, $destination.DIRECTORY_SEPARATOR))) {
                    continue;
                }

                $relative = $this->relativeToRoot($root, $path);
                if ($relative === null || $this->excluded($relative)) {
                    continue;
                }

                if (! is_readable($real)) {
                    throw new RuntimeException("Cannot read storage file [{$path}]. The backup would not contain uploaded files.");
                }

                $files[] = $path;
            }
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException('Cannot read a storage directory. The backup would not contain uploaded files. '.$e->getMessage(), 0, $e);
        }

        sort($files);

        return $files;
    }

    /**
     * Names this file may use inside the zip (current relative layout, and the
     * absolute layout written by older backups).
     *
     * @return list<string>
     */
    public function zipEntryNames(string $absolutePath): array
    {
        $names = [];
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($absolutePath, $base)) {
            $names[] = str_replace('\\', '/', substr($absolutePath, strlen($base)));
        }

        $names[] = str_replace('\\', '/', ltrim($absolutePath, DIRECTORY_SEPARATOR));

        return array_values(array_unique($names));
    }

    private function backupDestinationRealPath(): ?string
    {
        $name = trim($this->backupName ?? (string) config('backup.backup.name', ''), '/');
        if ($name === '') {
            return null;
        }

        $path = $this->storageRoot().DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.$name;
        $real = realpath($path);

        return $real === false ? $path : $real;
    }

    private function relativeToRoot(string $root, string $path): ?string
    {
        $prefix = $root.DIRECTORY_SEPARATOR;
        if (! str_starts_with($path, $prefix)) {
            $realRoot = realpath($root);
            $realPath = realpath($path);
            if ($realRoot === false || $realPath === false || ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR)) {
                return null;
            }

            $relative = substr($realPath, strlen($realRoot) + 1);
        } else {
            $relative = substr($path, strlen($prefix));
        }

        return str_replace('\\', '/', $relative);
    }

    private function excluded(string $relative): bool
    {
        return str_starts_with($relative, 'framework/')
            || str_starts_with($relative, 'logs/')
            || str_starts_with($relative, 'app/backup-temp/');
    }
}
