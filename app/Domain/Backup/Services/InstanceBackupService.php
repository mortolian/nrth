<?php

namespace App\Domain\Backup\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstanceBackupService
{
    public const RUNNING_CACHE_KEY = 'nrth.instance_backup.running';

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

    /**
     * @return list<array{filename: string, path: string, disk: string, date: string|null, size_bytes: int}>
     */
    public function listBackups(): array
    {
        return $this->destinations()
            ->flatMap(function (BackupDestination $destination): Collection {
                return $destination->backups()->map(function (Backup $backup) use ($destination): array {
                    return [
                        'filename' => basename($backup->path()),
                        'path' => $backup->path(),
                        'disk' => $destination->diskName(),
                        'date' => $backup->date()->toIso8601String(),
                        'size_bytes' => (int) $backup->sizeInBytes(),
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

    public function download(string $filename): StreamedResponse
    {
        $backup = $this->findBackup($filename);
        abort_unless($backup !== null && $backup->exists(), 404);

        $stream = $backup->stream();

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($backup->path()), [
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
        $disks = config('backup.backup.destination.disks', ['local']);

        return collect($disks)->map(
            fn (string $disk): BackupDestination => BackupDestination::create($disk, $backupName)
        );
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
