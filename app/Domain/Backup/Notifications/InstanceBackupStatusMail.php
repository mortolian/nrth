<?php

namespace App\Domain\Backup\Notifications;

use App\Domain\Backup\Services\InstanceBackupService;
use Illuminate\Notifications\Messages\MailMessage;
use Spatie\Backup\Config\Config as SpatieBackupConfig;

final class InstanceBackupStatusMail
{
    public static function message(bool $successful, ?string $error = null): MailMessage
    {
        $report = app(InstanceBackupService::class)->statusMailReport();
        $applicationName = (string) config('app.name', 'nrth');
        $from = app(SpatieBackupConfig::class)->notifications->mail->from;

        $subject = $applicationName.' backup failed';
        if ($successful) {
            $subject = $report['offsite_missing']
                ? $applicationName.' backup ready, offsite copy missing'
                : $applicationName.' backup ready';
        }

        return (new MailMessage)
            ->from($from->address, $from->name)
            ->subject($subject)
            ->markdown('emails.instance-backup-status', [
                'successful' => $successful,
                'applicationName' => $applicationName,
                'filename' => $report['filename'],
                'finishedAt' => $report['finished_at'],
                'warning' => $report['warning'],
                'destinations' => $report['destinations'],
                'error' => $error,
            ]);
    }
}
