<?php

namespace Tests\Unit\Instance;

use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Models\InstanceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class InstanceBackupDestinationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_props_never_expose_secrets(): void
    {
        app(InstanceBackupDestinationSettings::class)->update([
            's3' => [
                'enabled' => true,
                'key' => 'AKIATEST',
                'secret' => 'super-secret',
                'region' => 'eu-west-1',
                'bucket' => 'nrth-backups',
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'root' => 'prod',
            ],
            'path' => [
                'enabled' => false,
                'root' => '',
            ],
        ]);

        $props = app(InstanceBackupDestinationSettings::class)->publicProps();

        $this->assertTrue($props['s3']['enabled']);
        $this->assertTrue($props['s3']['key_set']);
        $this->assertTrue($props['s3']['secret_set']);
        $this->assertSame('nrth-backups', $props['s3']['bucket']);
        $this->assertArrayNotHasKey('key', $props['s3']);
        $this->assertArrayNotHasKey('secret', $props['s3']);
        $this->assertSame(['local', 'backup_s3'], $props['active_disks']);
        $this->assertSame(['local', 'backup_s3'], config('backup.backup.destination.disks'));
    }

    public function test_blank_secret_keeps_previous(): void
    {
        $settings = app(InstanceBackupDestinationSettings::class);
        $settings->update([
            's3' => [
                'enabled' => true,
                'key' => 'AKIATEST',
                'secret' => 'first-secret',
                'region' => 'us-east-1',
                'bucket' => 'bucket-a',
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => ['enabled' => false, 'root' => ''],
        ]);

        $settings->update([
            's3' => [
                'enabled' => true,
                'key' => '',
                'secret' => '',
                'region' => 'us-east-1',
                'bucket' => 'bucket-b',
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => ['enabled' => false, 'root' => ''],
        ]);

        $current = $settings->current();
        $this->assertSame('AKIATEST', $current['s3']['key']);
        $this->assertSame('first-secret', $current['s3']['secret']);
        $this->assertSame('bucket-b', $current['s3']['bucket']);

        $stored = InstanceSetting::query()->find(InstanceBackupDestinationSettings::SETTING_KEY)?->value;
        $this->assertIsArray($stored);
        $this->assertSame('first-secret', Crypt::decryptString($stored['s3']['secret_encrypted']));
    }

    public function test_path_destination_registers_disk(): void
    {
        $root = storage_path('framework/testing/backup-offsite');
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

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

        $this->assertSame(['local', 'backup_path'], config('backup.backup.destination.disks'));
        $this->assertSame($root, config('filesystems.disks.backup_path.root'));
    }
}
