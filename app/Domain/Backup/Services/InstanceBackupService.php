<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Enums\InstanceBackupRunStatus;
use App\Domain\Backup\Enums\InstanceBackupType;
use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
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

    /** @deprecated Cleared on read; kept so older workers cannot leave a stuck UI lock. */
    public const QUEUED_CACHE_KEY = 'nrth.instance_backup.queued';

    public const LAST_ERROR_CACHE_KEY = 'nrth.instance_backup.last_error';

    public function __construct(
        private readonly InstanceBackupRetentionSettings $retention,
        private readonly InstanceBackupTypeResolver $typeResolver,
    ) {}

    public function isRunning(): bool
    {
        // Drop the old queued lock — it stuck the UI when workers died before start.
        Cache::forget(self::QUEUED_CACHE_KEY);

        $startedAt = Cache::get(self::RUNNING_CACHE_KEY);
        if ($startedAt === null || $startedAt === false) {
            return false;
        }

        // Legacy boolean locks (and anything non-numeric) are treated as orphaned.
        if ($startedAt === true || ! is_numeric($startedAt)) {
            $this->markFinished();

            return false;
        }

        if (now()->getTimestamp() - (int) $startedAt > 70 * 60) {
            $this->markFinished();

            return false;
        }

        return true;
    }

    public function isBusy(): bool
    {
        return $this->isRunning();
    }

    public function markRunning(): void
    {
        Cache::put(self::RUNNING_CACHE_KEY, now()->getTimestamp(), now()->addMinutes(70));
        Cache::forget(self::QUEUED_CACHE_KEY);
    }

    public function markFinished(): void
    {
        Cache::forget(self::RUNNING_CACHE_KEY);
        Cache::forget(self::QUEUED_CACHE_KEY);
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

    public function hasActiveRun(): bool
    {
        $this->failStaleActiveRuns();

        return InstanceBackupRun::query()
            ->whereIn('status', [
                InstanceBackupRunStatus::Queued,
                InstanceBackupRunStatus::Processing,
            ])
            ->exists();
    }

    /**
     * Mark abandoned queued/processing runs as failed so they cannot block the UI forever.
     */
    public function failStaleActiveRuns(): void
    {
        InstanceBackupRun::query()
            ->whereIn('status', [
                InstanceBackupRunStatus::Queued,
                InstanceBackupRunStatus::Processing,
            ])
            ->where('updated_at', '<', now()->subMinutes(75))
            ->each(function (InstanceBackupRun $run): void {
                $run->forceFill([
                    'status' => InstanceBackupRunStatus::Failed,
                    'error_message' => $run->error_message
                        ?: 'Backup timed out or the worker restarted before the run could finish.',
                    'completed_at' => now(),
                ])->save();
            });
    }

    /**
     * Import zip files on disk that are not yet tracked as runs (scheduled backups, legacy files),
     * and attach orphan zips to stuck queued runs (e.g. after a Horizon worker restart mid-deploy).
     */
    public function syncDiskBackupsIntoRuns(): void
    {
        $this->failStaleActiveRuns();
        $this->reclaimOrphanZipsForActiveRuns();

        $hasFreshActiveRun = InstanceBackupRun::query()
            ->whereIn('status', [
                InstanceBackupRunStatus::Queued,
                InstanceBackupRunStatus::Processing,
            ])
            ->exists();

        foreach ($this->listBackups() as $backup) {
            $exists = InstanceBackupRun::query()
                ->where('disk', $backup['disk'])
                ->where('filename', $backup['filename'])
                ->exists();

            if ($exists) {
                continue;
            }

            // A fresh active run should claim the new zip itself — avoid a duplicate Ready row.
            if ($hasFreshActiveRun) {
                continue;
            }

            $backupAt = $backup['date'] ? Carbon::parse($backup['date']) : now();

            InstanceBackupRun::query()->create([
                'requested_by' => null,
                'status' => InstanceBackupRunStatus::Ready,
                'types' => $this->typeResolver->typesFor($backupAt),
                'filename' => $backup['filename'],
                'disk' => $backup['disk'],
                'storage_path' => $backup['path'],
                'file_size_bytes' => $backup['size_bytes'],
                'completed_at' => $backupAt,
                'error_message' => null,
                'created_at' => $backupAt,
                'updated_at' => $backupAt,
            ]);
        }
    }

    /**
     * If backup:run finished but the run row was never updated (stale worker code), attach the zip.
     */
    public function reclaimOrphanZipsForActiveRuns(): void
    {
        $activeRuns = InstanceBackupRun::query()
            ->whereIn('status', [
                InstanceBackupRunStatus::Queued,
                InstanceBackupRunStatus::Processing,
            ])
            ->orderBy('id')
            ->get();

        if ($activeRuns->isEmpty()) {
            return;
        }

        $trackedFilenames = InstanceBackupRun::query()
            ->whereNotNull('filename')
            ->pluck('filename')
            ->all();

        foreach ($this->listBackups() as $backup) {
            if (in_array($backup['filename'], $trackedFilenames, true)) {
                continue;
            }

            $backupAt = $backup['date'] ? Carbon::parse($backup['date']) : null;

            $run = $activeRuns->first(function (InstanceBackupRun $candidate) use ($backupAt): bool {
                if ($backupAt === null || $candidate->created_at === null) {
                    return true;
                }

                // Zip created around the time this run was queued.
                return $candidate->created_at->between(
                    $backupAt->copy()->subMinutes(30),
                    $backupAt->copy()->addMinutes(5),
                );
            });

            if ($run === null) {
                continue;
            }

            $run->forceFill([
                'status' => InstanceBackupRunStatus::Ready,
                'filename' => $backup['filename'],
                'disk' => $backup['disk'],
                'storage_path' => $backup['path'],
                'file_size_bytes' => $backup['size_bytes'],
                'error_message' => null,
                'completed_at' => $backupAt ?? now(),
                'types' => $run->typeList() !== []
                    ? $run->typeList()
                    : $this->typeResolver->typesFor($backupAt ?? now()),
            ])->save();

            $trackedFilenames[] = $backup['filename'];
            $activeRuns = $activeRuns->reject(fn (InstanceBackupRun $r): bool => $r->id === $run->id)->values();
        }
    }

    /**
     * Keep the newest N backups per type; delete zips that are not protected by any type.
     *
     * @return array{deleted: int, protected: int}
     */
    public function rotateByTypeCounts(): array
    {
        $settings = $this->retention->current();
        $keepByType = [
            InstanceBackupType::Daily->value => (int) $settings['keep_daily'],
            InstanceBackupType::Weekly->value => (int) $settings['keep_weekly'],
            InstanceBackupType::Monthly->value => (int) $settings['keep_monthly'],
            InstanceBackupType::Yearly->value => (int) $settings['keep_yearly'],
        ];

        $runs = InstanceBackupRun::query()
            ->where('status', InstanceBackupRunStatus::Ready)
            ->whereNotNull('filename')
            ->get()
            ->sortByDesc(fn (InstanceBackupRun $run): int => ($run->completed_at ?? $run->created_at)?->getTimestamp() ?? 0)
            ->values();

        foreach ($runs as $run) {
            if ($run->typeList() === []) {
                $at = $run->completed_at ?? $run->created_at ?? now();
                $run->forceFill(['types' => $this->typeResolver->typesFor($at)])->save();
            }
        }

        $protectedIds = [];
        foreach ($keepByType as $type => $keep) {
            if ($keep <= 0) {
                continue;
            }

            $count = 0;
            foreach ($runs as $run) {
                if (! $run->hasType($type)) {
                    continue;
                }
                $protectedIds[$run->id] = true;
                $count++;
                if ($count >= $keep) {
                    break;
                }
            }
        }

        $deleted = 0;
        foreach ($runs as $run) {
            if (isset($protectedIds[$run->id])) {
                continue;
            }

            $this->deleteRun($run);
            $deleted++;
        }

        $deleted += $this->enforceSizeCap($keepByType);

        return [
            'deleted' => $deleted,
            'protected' => count($protectedIds),
        ];
    }

    /**
     * @param  array<string, int>  $keepByType
     */
    private function enforceSizeCap(array $keepByType): int
    {
        $megabytes = $this->retention->current()['delete_oldest_backups_when_using_more_megabytes_than'];
        if ($megabytes === null) {
            return 0;
        }

        $maxBytes = (int) $megabytes * 1024 * 1024;
        $deleted = 0;

        while (true) {
            $runs = InstanceBackupRun::query()
                ->where('status', InstanceBackupRunStatus::Ready)
                ->whereNotNull('filename')
                ->get()
                ->sortBy(fn (InstanceBackupRun $run): int => ($run->completed_at ?? $run->created_at)?->getTimestamp() ?? 0)
                ->values();

            $totalBytes = (int) $runs->sum(fn (InstanceBackupRun $run): int => (int) ($run->file_size_bytes ?? 0));
            if ($totalBytes <= $maxBytes || $runs->isEmpty()) {
                break;
            }

            $protectedIds = $this->protectedIdsFor($runs, $keepByType);
            $candidate = $runs->first(function (InstanceBackupRun $run) use ($protectedIds): bool {
                if (! isset($protectedIds[$run->id])) {
                    return true;
                }

                // Prefer pure dailies among protected set only if somehow over cap with all protected —
                // never delete the newest protected of each type (already in protectedIds as newest N).
                return false;
            });

            // If everything is protected, drop the oldest pure-daily that is the least critical:
            // remove oldest protected daily-only backup beyond the first keep_daily? Actually plan says
            // never delete newest protected of each type. If still over cap, delete oldest protected
            // that is daily-only (not weekly/monthly/yearly).
            if ($candidate === null) {
                $candidate = $runs->first(function (InstanceBackupRun $run): bool {
                    $types = $run->typeList();

                    return $types === [InstanceBackupType::Daily->value];
                });
            }

            if ($candidate === null) {
                break;
            }

            $this->deleteRun($candidate);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  Collection<int, InstanceBackupRun>  $runs  newest-first or any order
     * @param  array<string, int>  $keepByType
     * @return array<int, true>
     */
    private function protectedIdsFor(Collection $runs, array $keepByType): array
    {
        $newestFirst = $runs
            ->sortByDesc(fn (InstanceBackupRun $run): int => ($run->completed_at ?? $run->created_at)?->getTimestamp() ?? 0)
            ->values();

        $protectedIds = [];
        foreach ($keepByType as $type => $keep) {
            if ($keep <= 0) {
                continue;
            }

            $count = 0;
            foreach ($newestFirst as $run) {
                if (! $run->hasType($type)) {
                    continue;
                }
                $protectedIds[$run->id] = true;
                $count++;
                if ($count >= $keep) {
                    break;
                }
            }
        }

        return $protectedIds;
    }

    public function deleteRun(InstanceBackupRun $run): void
    {
        if (filled($run->filename)) {
            $this->delete((string) $run->filename);
        }

        $run->delete();
    }

    /**
     * Metadata for the operator restore-guide panel (script generation in the UI).
     *
     * @return array{
     *     backup_name: string,
     *     container_zip_dir: string,
     *     db_connection: string,
     *     db_database: string,
     *     db_username: string,
     *     archive_password_configured: bool
     * }
     */
    public function restoreGuideProps(): array
    {
        $connection = (string) config('database.default');
        $backupName = (string) config('backup.backup.name', 'nrth');

        return [
            'backup_name' => $backupName,
            // Spatie stores zips under {backup.name}/ on the local disk (storage/app/private).
            'container_zip_dir' => 'storage/app/private/'.$backupName,
            'db_connection' => $connection,
            'db_database' => (string) config("database.connections.{$connection}.database"),
            'db_username' => (string) config("database.connections.{$connection}.username"),
            'archive_password_configured' => filled(config('backup.backup.password')),
        ];
    }

    /**
     * @return list<string>
     */
    public function backupFilenames(): array
    {
        return array_map(
            static fn (array $backup): string => $backup['filename'],
            $this->listBackups(),
        );
    }

    /**
     * @return list<array{filename: string, path: string, disk: string, date: string|null, size_bytes: int}>
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
