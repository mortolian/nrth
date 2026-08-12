<?php

namespace App\Listeners;

use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Domain\Instance\Services\InstanceMailSettings;
use App\Domain\Instance\Services\InstanceTimezoneSettings;
use Throwable;

/**
 * Re-apply DB-backed instance settings on long-lived workers.
 *
 * Octane resets config to the boot-time clone each request; Horizon keeps the
 * process boot config forever. Without this, Settings → Outbound email only
 * affects the SMTP test (same request) while queued mail keeps using .env.
 */
final class ApplyInstanceRuntimeSettings
{
    public function handle(mixed $event = null): void
    {
        try {
            app(InstanceMailSettings::class)->applyToRuntime();
        } catch (Throwable) {
            // DB may be unavailable during early install / migrate.
        }

        try {
            app(InstanceBackupDestinationSettings::class)->applyToRuntime();
        } catch (Throwable) {
            //
        }

        try {
            app(InstanceTimezoneSettings::class)->applyToRuntime();
        } catch (Throwable) {
            //
        }
    }
}
