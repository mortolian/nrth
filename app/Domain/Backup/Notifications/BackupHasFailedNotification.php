<?php

namespace App\Domain\Backup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification as SpatieBackupHasFailedNotification;

class BackupHasFailedNotification extends SpatieBackupHasFailedNotification
{
    public function toMail(): MailMessage
    {
        return InstanceBackupStatusMail::message(
            successful: false,
            error: $this->event->exception->getMessage(),
        );
    }
}
