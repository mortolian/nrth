<?php

namespace Tests\Unit\Instance;

use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Models\InstanceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstanceBackupRetentionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_count_based(): void
    {
        $settings = app(InstanceBackupRetentionSettings::class)->current();

        $this->assertSame(7, $settings['keep_daily']);
        $this->assertSame(8, $settings['keep_weekly']);
        $this->assertSame(4, $settings['keep_monthly']);
        $this->assertSame(2, $settings['keep_yearly']);
        $this->assertSame('sunday', $settings['weekly_on']);
        $this->assertSame(5000, $settings['delete_oldest_backups_when_using_more_megabytes_than']);
    }

    public function test_migrates_legacy_spatie_keys(): void
    {
        InstanceSetting::query()->create([
            'key' => InstanceBackupRetentionSettings::SETTING_KEY,
            'value' => [
                'keep_all_backups_for_days' => 5,
                'keep_daily_backups_for_days' => 16,
                'keep_weekly_backups_for_weeks' => 6,
                'keep_monthly_backups_for_months' => 3,
                'keep_yearly_backups_for_years' => 1,
                'delete_oldest_backups_when_using_more_megabytes_than' => 1000,
            ],
        ]);

        $settings = app(InstanceBackupRetentionSettings::class)->current();

        $this->assertSame(5, $settings['keep_daily']);
        $this->assertSame(6, $settings['keep_weekly']);
        $this->assertSame(3, $settings['keep_monthly']);
        $this->assertSame(1, $settings['keep_yearly']);
        $this->assertArrayNotHasKey('keep_all_backups_for_days', $settings);
        $this->assertArrayNotHasKey('keep_daily_backups_for_days', $settings);
    }

    public function test_update_persists_weekly_on(): void
    {
        $settings = app(InstanceBackupRetentionSettings::class)->update([
            'keep_daily' => 4,
            'keep_weekly' => 2,
            'keep_monthly' => 1,
            'keep_yearly' => 0,
            'weekly_on' => 'monday',
            'delete_oldest_backups_when_using_more_megabytes_than' => null,
        ]);

        $this->assertSame('monday', $settings['weekly_on']);
        $this->assertSame(4, app(InstanceBackupRetentionSettings::class)->current()['keep_daily']);
    }
}
