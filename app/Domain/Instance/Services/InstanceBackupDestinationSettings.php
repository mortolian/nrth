<?php

namespace App\Domain\Instance\Services;

use App\Models\InstanceSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InstanceBackupDestinationSettings
{
    public const SETTING_KEY = 'backup.destinations';

    public const DISK_S3 = 'backup_s3';

    public const DISK_PATH = 'backup_path';

    /**
     * @return array{
     *     s3: array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string}
     * }
     */
    public function defaults(): array
    {
        return [
            's3' => [
                'enabled' => false,
                'key' => '',
                'secret' => '',
                'region' => 'us-east-1',
                'bucket' => '',
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => [
                'enabled' => false,
                'root' => '',
            ],
        ];
    }

    /**
     * Decrypted settings for applying disks / testing (never send to Inertia).
     *
     * @return array{
     *     s3: array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string}
     * }
     */
    public function current(): array
    {
        $defaults = $this->defaults();

        if (! $this->tableReady()) {
            return $defaults;
        }

        $stored = InstanceSetting::query()->find(self::SETTING_KEY)?->value;
        if (! is_array($stored)) {
            return $defaults;
        }

        return $this->hydrateFromStored($stored);
    }

    /**
     * Safe props for the operator UI (secrets never included).
     *
     * @return array{
     *     s3: array{
     *         enabled: bool,
     *         key_set: bool,
     *         secret_set: bool,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string},
     *     active_disks: list<string>
     * }
     */
    public function publicProps(): array
    {
        $current = $this->current();

        return [
            's3' => [
                'enabled' => $current['s3']['enabled'],
                'key_set' => $current['s3']['key'] !== '',
                'secret_set' => $current['s3']['secret'] !== '',
                'region' => $current['s3']['region'],
                'bucket' => $current['s3']['bucket'],
                'endpoint' => $current['s3']['endpoint'],
                'use_path_style_endpoint' => $current['s3']['use_path_style_endpoint'],
                'root' => $current['s3']['root'],
            ],
            'path' => [
                'enabled' => $current['path']['enabled'],
                'root' => $current['path']['root'],
            ],
            'active_disks' => $this->destinationDiskNames($current),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     s3: array{
     *         enabled: bool,
     *         key_set: bool,
     *         secret_set: bool,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string},
     *     active_disks: list<string>
     * }
     */
    public function update(array $input): array
    {
        if (! $this->tableReady()) {
            throw ValidationException::withMessages([
                's3.enabled' => __('Instance settings are not available yet. Run migrations and try again.'),
            ]);
        }

        $previous = $this->current();
        $normalized = $this->normalizeInput($input, $previous);

        if ($normalized['s3']['enabled']) {
            if ($normalized['s3']['bucket'] === '') {
                throw ValidationException::withMessages([
                    's3.bucket' => __('Bucket is required when S3 offsite is enabled.'),
                ]);
            }
            if ($normalized['s3']['key'] === '' || $normalized['s3']['secret'] === '') {
                throw ValidationException::withMessages([
                    's3.key' => __('Access key and secret are required when S3 offsite is enabled.'),
                ]);
            }
        }

        if ($normalized['path']['enabled'] && $normalized['path']['root'] === '') {
            throw ValidationException::withMessages([
                'path.root' => __('Path is required when path/NFS offsite is enabled.'),
            ]);
        }

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $this->serializeForStorage($normalized)],
        );

        $this->applyToRuntime($normalized);

        return $this->publicProps();
    }

    /**
     * Register filesystem disks and Spatie destination list for the current request/process.
     */
    public function applyToRuntime(?array $settings = null): void
    {
        $settings ??= $this->current();

        $disks = config('filesystems.disks', []);

        if ($settings['s3']['enabled']) {
            $disks[self::DISK_S3] = [
                'driver' => 's3',
                'key' => $settings['s3']['key'],
                'secret' => $settings['s3']['secret'],
                'region' => $settings['s3']['region'],
                'bucket' => $settings['s3']['bucket'],
                'url' => null,
                'endpoint' => $settings['s3']['endpoint'],
                'use_path_style_endpoint' => $settings['s3']['use_path_style_endpoint'],
                'root' => $settings['s3']['root'] !== '' ? $settings['s3']['root'] : null,
                'throw' => false,
                'report' => false,
            ];
        } else {
            unset($disks[self::DISK_S3]);
        }

        if ($settings['path']['enabled'] && $settings['path']['root'] !== '') {
            $disks[self::DISK_PATH] = [
                'driver' => 'local',
                'root' => $settings['path']['root'],
                'throw' => false,
                'report' => false,
            ];
        } else {
            unset($disks[self::DISK_PATH]);
        }

        config([
            'filesystems.disks' => $disks,
            'backup.backup.destination.disks' => $this->destinationDiskNames($settings),
            'backup.backup.destination.continue_on_failure' => true,
        ]);

        // Forget cached disk instances so updated credentials take effect.
        foreach ([self::DISK_S3, self::DISK_PATH] as $name) {
            try {
                Storage::forgetDisk($name);
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * @return list<string>
     */
    public function destinationDiskNames(?array $settings = null): array
    {
        $settings ??= $this->current();
        $disks = ['local'];

        if ($settings['s3']['enabled']) {
            $disks[] = self::DISK_S3;
        }

        if ($settings['path']['enabled'] && $settings['path']['root'] !== '') {
            $disks[] = self::DISK_PATH;
        }

        return $disks;
    }

    /**
     * @return list<string>
     */
    public function offsiteDiskNames(?array $settings = null): array
    {
        return array_values(array_filter(
            $this->destinationDiskNames($settings),
            static fn (string $disk): bool => $disk !== 'local',
        ));
    }

    public function testS3(?array $override = null): void
    {
        $settings = $this->current();
        if (is_array($override)) {
            $settings['s3'] = array_merge($settings['s3'], $this->normalizeS3Override($override, $settings['s3']));
        }

        if ($settings['s3']['bucket'] === '' || $settings['s3']['key'] === '' || $settings['s3']['secret'] === '') {
            throw ValidationException::withMessages([
                's3.bucket' => __('Configure bucket, access key, and secret before testing.'),
            ]);
        }

        $probeSettings = $settings;
        $probeSettings['s3']['enabled'] = true;
        $this->applyToRuntime($probeSettings);

        $probe = 'nrth-backup-probe-'.bin2hex(random_bytes(8)).'.txt';
        Storage::disk(self::DISK_S3)->put($probe, 'nrth-probe');
        $exists = Storage::disk(self::DISK_S3)->exists($probe);
        Storage::disk(self::DISK_S3)->delete($probe);

        if (! $exists) {
            throw ValidationException::withMessages([
                's3.bucket' => __('S3 probe write succeeded but the object was not readable back.'),
            ]);
        }
    }

    public function testPath(?array $override = null): void
    {
        $settings = $this->current();
        if (is_array($override) && array_key_exists('root', $override)) {
            $settings['path']['root'] = trim((string) $override['root']);
        }

        $root = $settings['path']['root'];
        if ($root === '') {
            throw ValidationException::withMessages([
                'path.root' => __('Configure an absolute path before testing.'),
            ]);
        }

        if (! str_starts_with($root, '/')) {
            throw ValidationException::withMessages([
                'path.root' => __('Path must be an absolute filesystem path.'),
            ]);
        }

        $probeSettings = $settings;
        $probeSettings['path']['enabled'] = true;
        $this->applyToRuntime($probeSettings);

        $probe = 'nrth-backup-probe-'.bin2hex(random_bytes(8)).'.txt';
        Storage::disk(self::DISK_PATH)->put($probe, 'nrth-probe');
        $exists = Storage::disk(self::DISK_PATH)->exists($probe);
        Storage::disk(self::DISK_PATH)->delete($probe);

        if (! $exists) {
            throw ValidationException::withMessages([
                'path.root' => __('Path probe write succeeded but the file was not readable back.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{
     *     s3: array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string}
     * }
     */
    private function hydrateFromStored(array $stored): array
    {
        $defaults = $this->defaults();
        $s3 = array_merge($defaults['s3'], is_array($stored['s3'] ?? null) ? $stored['s3'] : []);
        $path = array_merge($defaults['path'], is_array($stored['path'] ?? null) ? $stored['path'] : []);

        return [
            's3' => [
                'enabled' => (bool) ($s3['enabled'] ?? false),
                'key' => $this->decryptString($s3['key_encrypted'] ?? null),
                'secret' => $this->decryptString($s3['secret_encrypted'] ?? null),
                'region' => trim((string) ($s3['region'] ?? 'us-east-1')) ?: 'us-east-1',
                'bucket' => trim((string) ($s3['bucket'] ?? '')),
                'endpoint' => $this->nullableString($s3['endpoint'] ?? null),
                'use_path_style_endpoint' => (bool) ($s3['use_path_style_endpoint'] ?? false),
                'root' => trim((string) ($s3['root'] ?? '')),
            ],
            'path' => [
                'enabled' => (bool) ($path['enabled'] ?? false),
                'root' => trim((string) ($path['root'] ?? '')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{
     *     s3: array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string}
     * }  $previous
     * @return array{
     *     s3: array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string}
     * }
     */
    private function normalizeInput(array $input, array $previous): array
    {
        $s3In = is_array($input['s3'] ?? null) ? $input['s3'] : [];
        $pathIn = is_array($input['path'] ?? null) ? $input['path'] : [];

        $key = trim((string) ($s3In['key'] ?? ''));
        $secret = trim((string) ($s3In['secret'] ?? ''));

        return [
            's3' => [
                'enabled' => filter_var($s3In['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'key' => $key !== '' ? $key : $previous['s3']['key'],
                'secret' => $secret !== '' ? $secret : $previous['s3']['secret'],
                'region' => trim((string) ($s3In['region'] ?? $previous['s3']['region'])) ?: 'us-east-1',
                'bucket' => trim((string) ($s3In['bucket'] ?? $previous['s3']['bucket'])),
                'endpoint' => $this->nullableString($s3In['endpoint'] ?? $previous['s3']['endpoint']),
                'use_path_style_endpoint' => filter_var(
                    $s3In['use_path_style_endpoint'] ?? $previous['s3']['use_path_style_endpoint'],
                    FILTER_VALIDATE_BOOLEAN,
                ),
                'root' => trim((string) ($s3In['root'] ?? $previous['s3']['root'])),
            ],
            'path' => [
                'enabled' => filter_var($pathIn['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'root' => trim((string) ($pathIn['root'] ?? $previous['path']['root'])),
            ],
        ];
    }

    /**
     * @param  array{
     *     s3: array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     },
     *     path: array{enabled: bool, root: string}
     * }  $settings
     * @return array<string, mixed>
     */
    private function serializeForStorage(array $settings): array
    {
        return [
            's3' => [
                'enabled' => $settings['s3']['enabled'],
                'key_encrypted' => $settings['s3']['key'] !== '' ? Crypt::encryptString($settings['s3']['key']) : null,
                'secret_encrypted' => $settings['s3']['secret'] !== '' ? Crypt::encryptString($settings['s3']['secret']) : null,
                'region' => $settings['s3']['region'],
                'bucket' => $settings['s3']['bucket'],
                'endpoint' => $settings['s3']['endpoint'],
                'use_path_style_endpoint' => $settings['s3']['use_path_style_endpoint'],
                'root' => $settings['s3']['root'],
            ],
            'path' => [
                'enabled' => $settings['path']['enabled'],
                'root' => $settings['path']['root'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @param  array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     }  $previous
     * @return array{
     *         enabled: bool,
     *         key: string,
     *         secret: string,
     *         region: string,
     *         bucket: string,
     *         endpoint: string|null,
     *         use_path_style_endpoint: bool,
     *         root: string
     *     }
     */
    private function normalizeS3Override(array $override, array $previous): array
    {
        $key = trim((string) ($override['key'] ?? ''));
        $secret = trim((string) ($override['secret'] ?? ''));

        return [
            'enabled' => true,
            'key' => $key !== '' ? $key : $previous['key'],
            'secret' => $secret !== '' ? $secret : $previous['secret'],
            'region' => trim((string) ($override['region'] ?? $previous['region'])) ?: 'us-east-1',
            'bucket' => trim((string) ($override['bucket'] ?? $previous['bucket'])),
            'endpoint' => array_key_exists('endpoint', $override)
                ? $this->nullableString($override['endpoint'])
                : $previous['endpoint'],
            'use_path_style_endpoint' => array_key_exists('use_path_style_endpoint', $override)
                ? filter_var($override['use_path_style_endpoint'], FILTER_VALIDATE_BOOLEAN)
                : $previous['use_path_style_endpoint'],
            'root' => array_key_exists('root', $override)
                ? trim((string) $override['root'])
                : $previous['root'],
        ];
    }

    private function decryptString(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return '';
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('instance_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
