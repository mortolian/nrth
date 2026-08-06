<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Notifications\SafeBackupEventHandler;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Config\Config as SpatieBackupConfig;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Notifications\EventHandler;
use Spatie\Backup\Notifications\Notifiable;
use Tests\TestCase;

class SafeBackupEventHandlerTest extends TestCase
{
    public function test_app_resolves_safe_backup_event_handler(): void
    {
        $this->assertInstanceOf(
            SafeBackupEventHandler::class,
            app(EventHandler::class),
        );
    }

    public function test_notification_transport_errors_do_not_bubble(): void
    {
        config([
            'backup.notifications.notifiable' => ThrowingBackupNotifiable::class,
            'backup.notifications.mail.to' => 'ops@example.com',
            'backup.notifications.mail.from.address' => 'noreply@example.com',
            'backup.notifications.mail.from.name' => 'nrth',
        ]);
        SpatieBackupConfig::rebind();
        app()->forgetInstance(SpatieBackupConfig::class);

        EventHandler::enable();
        Log::spy();

        event(new BackupWasSuccessful('local', 'nrth'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Instance backup notification could not be sent.'
                    && ($context['event'] ?? null) === BackupWasSuccessful::class
                    && str_contains((string) ($context['message'] ?? ''), '550');
            })
            ->atLeast()
            ->once();
    }
}

class ThrowingBackupNotifiable extends Notifiable
{
    public function notify($instance): void
    {
        throw new \RuntimeException('550-From header sender domain not verified (example.com)');
    }
}
