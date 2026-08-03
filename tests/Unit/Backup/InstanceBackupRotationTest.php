<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstanceBackupRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_rotation_keeps_count_and_protects_weekly_beyond_daily(): void
    {
        app(InstanceBackupRetentionSettings::class)->update([
            'keep_daily' => 2,
            'keep_weekly' => 1,
            'keep_monthly' => 0,
            'keep_yearly' => 0,
            'weekly_on' => 'sunday',
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ]);

        $weekly = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => null,
            'types' => ['daily', 'weekly'],
            'filename' => 'weekly.zip',
            'storage_path' => 'nrth/weekly.zip',
            'completed_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);
        Storage::disk('local')->put('nrth/weekly.zip', 'weekly');

        $oldDaily = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => null,
            'types' => ['daily'],
            'filename' => 'old-daily.zip',
            'storage_path' => 'nrth/old-daily.zip',
            'completed_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
        ]);
        Storage::disk('local')->put('nrth/old-daily.zip', 'old');

        $newerDaily = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => null,
            'types' => ['daily'],
            'filename' => 'newer-daily.zip',
            'storage_path' => 'nrth/newer-daily.zip',
            'completed_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);
        Storage::disk('local')->put('nrth/newer-daily.zip', 'newer');

        $newestDaily = InstanceBackupRun::factory()->ready()->create([
            'requested_by' => null,
            'types' => ['daily'],
            'filename' => 'newest-daily.zip',
            'storage_path' => 'nrth/newest-daily.zip',
            'completed_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        Storage::disk('local')->put('nrth/newest-daily.zip', 'newest');

        $result = app(InstanceBackupService::class)->rotateByTypeCounts();

        $this->assertGreaterThanOrEqual(1, $result['deleted']);
        $this->assertNotNull($weekly->fresh());
        $this->assertNotNull($newerDaily->fresh());
        $this->assertNotNull($newestDaily->fresh());
        $this->assertNull($oldDaily->fresh());
    }
}
