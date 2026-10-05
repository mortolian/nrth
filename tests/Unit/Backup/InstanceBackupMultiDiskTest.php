<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstanceBackupMultiDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_removes_filename_from_all_destination_disks(): void
    {
        Storage::fake('local');
        Storage::fake('backup_path');

        Config::set('backup.backup.name', 'nrth');
        Config::set('backup.backup.destination.disks', ['local', 'backup_path']);
        Config::set('filesystems.disks.backup_path', [
            'driver' => 'local',
            'root' => Storage::disk('backup_path')->path(''),
            'throw' => false,
        ]);

        Storage::disk('local')->put('nrth/demo.zip', 'local-bytes');
        Storage::disk('backup_path')->put('nrth/demo.zip', 'offsite-bytes');

        $deleted = app(InstanceBackupService::class)->delete('demo.zip');

        $this->assertTrue($deleted);
        $this->assertFalse(Storage::disk('local')->exists('nrth/demo.zip'));
        $this->assertFalse(Storage::disk('backup_path')->exists('nrth/demo.zip'));
    }

    public function test_sync_ignores_non_local_disk_files(): void
    {
        Storage::fake('local');
        Storage::fake('backup_path');

        Config::set('backup.backup.name', 'nrth');
        Config::set('backup.backup.destination.disks', ['local', 'backup_path']);
        Config::set('filesystems.disks.backup_path', [
            'driver' => 'local',
            'root' => Storage::disk('backup_path')->path(''),
            'throw' => false,
        ]);

        Storage::disk('backup_path')->put('nrth/only-offsite.zip', 'offsite');

        app(InstanceBackupService::class)->syncDiskBackupsIntoRuns();

        $this->assertDatabaseMissing('instance_backup_runs', [
            'filename' => 'only-offsite.zip',
        ]);
    }

    public function test_mirror_warning_copies_local_zip_onto_path_destination(): void
    {
        Storage::fake('local');

        $root = storage_path('framework/testing/backup-offsite-mirror');
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        Config::set('backup.backup.name', 'nrth');
        app(InstanceBackupDestinationSettings::class)->update([
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
                'enabled' => true,
                'root' => $root,
            ],
        ]);

        Storage::disk('local')->put('nrth/local-only.zip', 'local-zip');

        $warning = app(InstanceBackupService::class)->mirrorWarningFor('local-only.zip');

        $this->assertNull($warning);
        $this->assertSame('local-zip', file_get_contents($root.'/nrth/local-only.zip'));
    }

    public function test_mirror_warning_explains_when_path_cannot_be_written(): void
    {
        Storage::fake('local');

        Config::set('backup.backup.name', 'nrth');
        app(InstanceBackupDestinationSettings::class)->update([
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
                'enabled' => true,
                'root' => '/dev/null',
            ],
        ]);

        Storage::disk('local')->put('nrth/local-only.zip', 'local-zip');

        $warning = app(InstanceBackupService::class)->mirrorWarningFor('local-only.zip');

        $this->assertNotNull($warning);
        $this->assertStringContainsString('path/NFS', $warning);
        $this->assertGreaterThan(strlen('Offsite mirror missing on: path/NFS.'), strlen($warning));
    }
}
