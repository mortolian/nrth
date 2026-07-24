<?php

namespace App\Domain\Backup\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstanceBackupService
{
    public const RUNNING_CACHE_KEY = 'nrth.instance_backup.running';

    public const LAST_ERROR_CACHE_KEY = 'nrth.instance_backup.last_error';

    public function isRunning(): bool
    {
        return (bool) Cache::get(self::RUNNING_CACHE_KEY, false);
    }

    public function markRunning(): void
    {
        Cache::put(self::RUNNING_CACHE_KEY, true, now()->addHour());
    }

    public function markFinished(): void
    {
        Cache::forget(self::RUNNING_CACHE_KEY);
    }

    public function recordFailure(string $message): void
    {
        Cache::put(self::LAST_ERROR_CACHE_KEY, $message, now()->addDay());
    }

    public function clearLastError(): void
    {
        Cache::forget(self::LAST_ERROR_CACHE_KEY);
    }

    public function lastError(): ?string
    {
        $error = Cache::get(self::LAST_ERROR_CACHE_KEY);

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * @return list<array{filename: string, path: string, disk: string, date: string|null, size_bytes: int, download_url: string}>
     */
    public function listBackups(): array
    {
        return $this->destinations()
            ->flatMap(function (BackupDestination $destination): Collection {
                return $destination->backups()->map(function (Backup $backup) use ($destination): array {
                    $filename = basename($backup->path());

                    return [
                        'filename' => $filename,
                        'path' => $backup->path(),
                        'disk' => $destination->diskName(),
                        'date' => $backup->date()->toIso8601String(),
                        'size_bytes' => (int) $backup->sizeInBytes(),
                        'download_url' => route('backups-exports.backups.download', ['filename' => $filename]),
                    ];
                });
            })
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    public function latestBackupAt(): ?CarbonInterface
    {
        $backups = $this->listBackups();

        if ($backups === []) {
            return null;
        }

        return Carbon::parse($backups[0]['date']);
    }

    public function findBackup(string $filename): ?Backup
    {
        $safeName = $this->sanitizeFilename($filename);
        if ($safeName === null) {
            return null;
        }

        foreach ($this->destinations() as $destination) {
            foreach ($destination->backups() as $backup) {
                if (basename($backup->path()) === $safeName) {
                    return $backup;
                }
            }
        }

        return null;
    }

    public function download(string $filename): BinaryFileResponse|StreamedResponse
    {
        $backup = $this->findBackup($filename);
        abort_unless($backup !== null && $backup->exists(), 404);

        $name = basename($backup->path());

        // Prefer a real filesystem download. Octane/Swoole often hangs or returns an
        // empty body for streamDownload() + fpassthru() on large zip archives.
        foreach ($this->destinationDiskNames() as $diskName) {
            $disk = Storage::disk($diskName);
            $relative = $backup->path();

            if (! $disk->exists($relative)) {
                continue;
            }

            try {
                $absolute = $disk->path($relative);
            } catch (\Throwable) {
                break;
            }

            if (is_string($absolute) && is_file($absolute)) {
                return response()->download($absolute, $name, [
                    'Content-Type' => 'application/zip',
                ]);
            }
        }

        $stream = $backup->stream();

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $name, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function delete(string $filename): bool
    {
        $backup = $this->findBackup($filename);
        if ($backup === null || ! $backup->exists()) {
            return false;
        }

        $backup->delete();

        return true;
    }

    /**
     * @return Collection<int, BackupDestination>
     */
    private function destinations(): Collection
    {
        $backupName = (string) config('backup.backup.name');

        return collect($this->destinationDiskNames())->map(
            fn (string $disk): BackupDestination => BackupDestination::create($disk, $backupName)
        );
    }

    /**
     * @return list<string>
     */
    private function destinationDiskNames(): array
    {
        /** @var list<string>|string $disks */
        $disks = config('backup.backup.destination.disks', ['local']);

        return array_values(array_filter((array) $disks, fn ($disk): bool => is_string($disk) && $disk !== ''));
    }

    private function sanitizeFilename(string $filename): ?string
    {
        $name = basename(str_replace(["\0", '\\'], '', $filename));

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9._-]+\.zip$/', $name)) {
            return null;
        }

        return $name;
    }
}
