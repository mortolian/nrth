<?php

namespace App\Domain\Instance\Services;

use App\Models\InstanceSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class InstanceBackupRetentionSettings
{
    public const SETTING_KEY = 'backup.cleanup';

    /** @var list<string> */
    public const WEEKDAYS = [
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
    ];

    /**
     * @return array{
     *     keep_daily: int,
     *     keep_weekly: int,
     *     keep_monthly: int,
     *     keep_yearly: int,
     *     weekly_on: string,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
     * }
     */
    public function defaults(): array
    {
        return [
            'keep_daily' => 7,
            'keep_weekly' => 8,
            'keep_monthly' => 4,
            'keep_yearly' => 2,
            'weekly_on' => 'sunday',
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ];
    }

    /**
     * @return array{
     *     keep_daily: int,
     *     keep_weekly: int,
     *     keep_monthly: int,
     *     keep_yearly: int,
     *     weekly_on: string,
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

        return $this->normalize($this->migrateLegacyKeys($stored));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     keep_daily: int,
     *     keep_weekly: int,
     *     keep_monthly: int,
     *     keep_yearly: int,
     *     weekly_on: string,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
     * }
     */
    public function update(array $input): array
    {
        if (! $this->tableReady()) {
            throw ValidationException::withMessages([
                'keep_daily' => __('Instance settings are not available yet. Run migrations and try again.'),
            ]);
        }

        $normalized = $this->normalize($input);

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $normalized],
        );

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     keep_daily: int,
     *     keep_weekly: int,
     *     keep_monthly: int,
     *     keep_yearly: int,
     *     weekly_on: string,
     *     delete_oldest_backups_when_using_more_megabytes_than: int|null
     * }
     */
    public function normalize(array $input): array
    {
        $input = $this->migrateLegacyKeys($input);

        $megabytes = $input['delete_oldest_backups_when_using_more_megabytes_than'] ?? null;
        if ($megabytes === '' || $megabytes === null) {
            $megabytes = null;
        } else {
            $megabytes = (int) $megabytes;
            if ($megabytes <= 0) {
                $megabytes = null;
            }
        }

        $weeklyOn = strtolower((string) ($input['weekly_on'] ?? 'sunday'));
        if (! in_array($weeklyOn, self::WEEKDAYS, true)) {
            $weeklyOn = 'sunday';
        }

        return [
            'keep_daily' => max(1, min(90, (int) ($input['keep_daily'] ?? 7))),
            'keep_weekly' => max(0, min(104, (int) ($input['keep_weekly'] ?? 8))),
            'keep_monthly' => max(0, min(60, (int) ($input['keep_monthly'] ?? 4))),
            'keep_yearly' => max(0, min(20, (int) ($input['keep_yearly'] ?? 2))),
            'weekly_on' => $weeklyOn,
            'delete_oldest_backups_when_using_more_megabytes_than' => $megabytes === null
                ? null
                : max(100, min(200000, $megabytes)),
        ];
    }

    /**
     * Map legacy Spatie age-window keys onto count-based retention.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function migrateLegacyKeys(array $input): array
    {
        if (! array_key_exists('keep_daily', $input) && array_key_exists('keep_all_backups_for_days', $input)) {
            $input['keep_daily'] = $input['keep_all_backups_for_days'];
        }

        if (! array_key_exists('keep_weekly', $input) && array_key_exists('keep_weekly_backups_for_weeks', $input)) {
            $input['keep_weekly'] = $input['keep_weekly_backups_for_weeks'];
        }

        if (! array_key_exists('keep_monthly', $input) && array_key_exists('keep_monthly_backups_for_months', $input)) {
            $input['keep_monthly'] = $input['keep_monthly_backups_for_months'];
        }

        if (! array_key_exists('keep_yearly', $input) && array_key_exists('keep_yearly_backups_for_years', $input)) {
            $input['keep_yearly'] = $input['keep_yearly_backups_for_years'];
        }

        unset(
            $input['keep_all_backups_for_days'],
            $input['keep_daily_backups_for_days'],
            $input['keep_weekly_backups_for_weeks'],
            $input['keep_monthly_backups_for_months'],
            $input['keep_yearly_backups_for_years'],
        );

        return $input;
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
