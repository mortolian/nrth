<?php

namespace App\Domain\Instance\Services;

use App\Models\InstanceSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class InstanceBackupRetentionSettings
{
    public const SETTING_KEY = 'backup.cleanup';

    /**
     * Package defaults (must stay aligned with config/backup.php).
     *
     * @return array{
     *     keep_all_backups_for_days: int,
     *     keep_daily_backups_for_days: int,
     *     keep_weekly_backups_for_weeks: int,
     *     keep_monthly_backups_for_months: int,
     *     keep_yearly_backups_for_years: int,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
     * }
     */
    public function defaults(): array
    {
        return [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ];
    }

    /**
     * Effective retention settings (stored overlay on config defaults).
     *
     * @return array{
     *     keep_all_backups_for_days: int,
     *     keep_daily_backups_for_days: int,
     *     keep_weekly_backups_for_weeks: int,
     *     keep_monthly_backups_for_months: int,
     *     keep_yearly_backups_for_years: int,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
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

        return $this->normalize(array_merge($defaults, $stored));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     keep_all_backups_for_days: int,
     *     keep_daily_backups_for_days: int,
     *     keep_weekly_backups_for_weeks: int,
     *     keep_monthly_backups_for_months: int,
     *     keep_yearly_backups_for_years: int,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
     * }
     */
    public function update(array $input): array
    {
        if (! $this->tableReady()) {
            throw ValidationException::withMessages([
                'keep_all_backups_for_days' => __('Instance settings are not available yet. Run migrations and try again.'),
            ]);
        }

        $normalized = $this->normalize($input);

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $normalized],
        );

        $this->applyToConfig($normalized);

        return $normalized;
    }

    public function applyToConfig(?array $strategy = null): void
    {
        $strategy ??= $this->current();
        config(['backup.cleanup.default_strategy' => $strategy]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     keep_all_backups_for_days: int,
     *     keep_daily_backups_for_days: int,
     *     keep_weekly_backups_for_weeks: int,
     *     keep_monthly_backups_for_months: int,
     *     keep_yearly_backups_for_years: int,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
     * }
     */
    public function normalize(array $input): array
    {
        $megabytes = $input['delete_oldest_backups_when_using_more_megabytes_than'] ?? null;
        if ($megabytes === '' || $megabytes === null) {
            $megabytes = null;
        } else {
            $megabytes = (int) $megabytes;
            if ($megabytes <= 0) {
                $megabytes = null;
            }
        }

        $normalized = [
            'keep_all_backups_for_days' => max(1, min(90, (int) ($input['keep_all_backups_for_days'] ?? 7))),
            'keep_daily_backups_for_days' => max(0, min(90, (int) ($input['keep_daily_backups_for_days'] ?? 16))),
            'keep_weekly_backups_for_weeks' => max(0, min(104, (int) ($input['keep_weekly_backups_for_weeks'] ?? 8))),
            'keep_monthly_backups_for_months' => max(0, min(60, (int) ($input['keep_monthly_backups_for_months'] ?? 4))),
            'keep_yearly_backups_for_years' => max(0, min(20, (int) ($input['keep_yearly_backups_for_years'] ?? 2))),
            'delete_oldest_backups_when_using_more_megabytes_than' => $megabytes === null
                ? null
                : max(100, min(200000, $megabytes)),
        ];

        return $normalized;
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('instance_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
