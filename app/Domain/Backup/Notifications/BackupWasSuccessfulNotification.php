<?php

namespace App\Domain\Backup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification as SpatieBackupWasSuccessfulNotification;

class BackupWasSuccessfulNotification extends SpatieBackupWasSuccessfulNotification
{
    public function toMail(): MailMessage
    {
        return InstanceBackupStatusMail::message(successful: true);
    }
}
