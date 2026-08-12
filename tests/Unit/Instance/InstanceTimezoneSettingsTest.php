<?php

namespace Tests\Unit\Instance;

use App\Domain\Instance\Services\InstanceTimezoneSettings;
use App\Listeners\ApplyInstanceRuntimeSettings;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstanceTimezoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_config_timezone_when_unset(): void
    {
        config(['app.timezone' => 'UTC']);

        $settings = app(InstanceTimezoneSettings::class);
        $settings->applyToRuntime();

        $this->assertSame('UTC', $settings->resolved());
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_update_persists_and_applies_runtime_timezone(): void
    {
        $settings = app(InstanceTimezoneSettings::class);
        $settings->update(['timezone' => 'Africa/Johannesburg']);

        $stored = InstanceSetting::query()->find(InstanceTimezoneSettings::SETTING_KEY)?->value;
        $this->assertSame(['timezone' => 'Africa/Johannesburg'], $stored);
        $this->assertSame('Africa/Johannesburg', config('app.timezone'));
        $this->assertSame('Africa/Johannesburg', date_default_timezone_get());
    }

    public function test_apply_instance_runtime_settings_applies_timezone(): void
    {
        app(InstanceTimezoneSettings::class)->update(['timezone' => 'Europe/London']);
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        app(ApplyInstanceRuntimeSettings::class)->handle();

        $this->assertSame('Europe/London', config('app.timezone'));
    }

    public function test_team_timezone_falls_back_to_instance_then_can_override(): void
    {
        app(InstanceTimezoneSettings::class)->update(['timezone' => 'Africa/Johannesburg']);

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $this->assertSame('Africa/Johannesburg', $team->timezone());

        $team->business_settings = array_replace_recursive(
            $team->mergedBusinessSettings(),
            ['timezone' => 'America/New_York'],
        );
        $team->save();

        $this->assertSame('America/New_York', $team->fresh()->timezone());
    }
}
