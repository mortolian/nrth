<?php

namespace App\Domain\Backup\Notifications;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Notifications\EventHandler;
use Throwable;

/**
 * Spatie's default handler lets notification transport errors abort backup:run.
 * A verified zip should still be Ready even when SMTP rejects the status email.
 */
final class SafeBackupEventHandler extends EventHandler
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(array_keys(self::$eventToNotificationMap), function (object $event): void {
            if (! static::$enabled) {
                return;
            }

            try {
                $notifiable = $this->determineNotifiable();
                $notification = $this->determineNotification($event);
                $notifiable->notify($notification);
            } catch (Throwable $e) {
                Log::warning('Instance backup notification could not be sent.', [
                    'event' => $event::class,
                    'message' => $e->getMessage(),
                ]);
                report($e);
            }
        });
    }
}
