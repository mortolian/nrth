<?php

namespace App\Domain\Instance\Services;

use App\Models\InstanceSetting;
use App\Support\Timezones;
use Illuminate\Support\Facades\Schema;

final class InstanceTimezoneSettings
{
    public const SETTING_KEY = 'app.timezone';

    /**
     * Env / config baseline before instance DB override (Octane-safe).
     */
    private static ?string $envTimezoneBaseline = null;

    /**
     * @return array{timezone: string}
     */
    public function defaults(): array
    {
        return [
            'timezone' => Timezones::normalize(
                (string) (self::$envTimezoneBaseline ?? config('app.timezone') ?? 'UTC')
            ),
        ];
    }

    /**
     * @return array{timezone: string}
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

        return [
            'timezone' => Timezones::normalize(
                isset($stored['timezone']) ? (string) $stored['timezone'] : null,
                $defaults['timezone']
            ),
        ];
    }

    /**
     * @return array{timezone: string, summary: string, env_timezone: string}
     */
    public function publicProps(): array
    {
        $current = $this->current();
        $env = Timezones::normalize((string) (self::$envTimezoneBaseline ?? config('app.timezone') ?? 'UTC'));

        return [
            'timezone' => $current['timezone'],
            'env_timezone' => $env,
            'summary' => $current['timezone'],
        ];
    }

    /**
     * @param  array{timezone?: string}  $input
     */
    public function update(array $input): void
    {
        $timezone = Timezones::normalize($input['timezone'] ?? null, $this->defaults()['timezone']);

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => ['timezone' => $timezone]],
        );

        $this->applyToRuntime();
    }

    public function resolved(): string
    {
        return $this->current()['timezone'];
    }

    public function applyToRuntime(): void
    {
        if (self::$envTimezoneBaseline === null) {
            self::$envTimezoneBaseline = Timezones::normalize((string) config('app.timezone', 'UTC'));
        }

        config(['app.timezone' => $this->resolved()]);
        date_default_timezone_set((string) config('app.timezone'));
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
