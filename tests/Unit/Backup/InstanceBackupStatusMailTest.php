<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Notifications\InstanceBackupStatusMail;
use App\Domain\Backup\Services\InstanceBackupService;
use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Backup\Config\Config as SpatieBackupConfig;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Tests\TestCase;

class InstanceBackupStatusMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_mail_lists_s3_and_path_copies(): void
    {
        Storage::fake('local');

        $root = storage_path('framework/testing/backup-status-mail');
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        Config::set('backup.backup.name', 'nrth');
        Config::set('app.name', 'nrth');
        app(InstanceBackupDestinationSettings::class)->update([
            's3' => [
                'enabled' => false,
                'key' => '',
                'secret' => '',
                'region' => 'us-east-1',
                'bucket' => '',
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => [
                'enabled' => true,
                'root' => $root,
            ],
        ]);

        Storage::disk('local')->put('nrth/2026-10-05-12-00-00.zip', 'local-zip');
        if (! is_dir($root.'/nrth')) {
            mkdir($root.'/nrth', 0777, true);
        }
        file_put_contents($root.'/nrth/2026-10-05-12-00-00.zip', 'offsite-zip');

        $report = app(InstanceBackupService::class)->statusMailReport('2026-10-05-12-00-00.zip');

        $this->assertSame('Saved', $report['destinations'][0]['status']);
        $this->assertSame('S3', $report['destinations'][1]['label']);
        $this->assertSame('Not enabled', $report['destinations'][1]['status']);
        $this->assertSame('Path / NFS', $report['destinations'][2]['label']);
        $this->assertSame('Copied', $report['destinations'][2]['status']);
        $this->assertFalse($report['offsite_missing']);

        $this->prepareMailConfig();
        $html = InstanceBackupStatusMail::message(successful: true)->render()->toHtml();

        $this->assertStringContainsString('Backup ready', $html);
        $this->assertStringContainsString('S3', $html);
        $this->assertStringContainsString('Not enabled', $html);
        $this->assertStringContainsString('Path / NFS', $html);
        $this->assertStringContainsString('Copied', $html);
        $this->assertStringContainsString('2026-10-05-12-00-00.zip', $html);
    }

    public function test_status_mail_marks_a_missing_path_copy(): void
    {
        Storage::fake('local');

        $root = storage_path('framework/testing/backup-status-mail-missing');
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        Config::set('backup.backup.name', 'nrth');
        Config::set('app.name', 'nrth');
        app(InstanceBackupDestinationSettings::class)->update([
            's3' => [
                'enabled' => false,
                'key' => '',
                'secret' => '',
                'region' => 'us-east-1',
                'bucket' => '',
                'endpoint' => null,
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => [
                'enabled' => true,
                'root' => $root,
            ],
        ]);

        Storage::disk('local')->put('nrth/2026-10-05-12-00-00.zip', 'local-zip');

        $this->prepareMailConfig();
        $mail = InstanceBackupStatusMail::message(successful: true);

        $this->assertSame('nrth backup ready, offsite copy missing', $mail->subject);
        $html = $mail->render()->toHtml();
        $this->assertStringContainsString('Path / NFS', $html);
        $this->assertStringContainsString('Missing', $html);
    }

    public function test_failed_status_mail_includes_the_error_and_destinations(): void
    {
        Storage::fake('local');
        Config::set('backup.backup.name', 'nrth');
        Config::set('app.name', 'nrth');
        $this->prepareMailConfig();

        $notification = new \App\Domain\Backup\Notifications\BackupHasFailedNotification(
            new BackupHasFailed(new RuntimeException('Database dump failed.')),
        );
        $mail = $notification->toMail();
        $html = $mail->render()->toHtml();

        $this->assertSame('nrth backup failed', $mail->subject);
        $this->assertStringContainsString('Database dump failed.', $html);
        $this->assertStringContainsString('S3', $html);
        $this->assertStringContainsString('Path / NFS', $html);
    }

    public function test_success_event_uses_the_destination_mail(): void
    {
        Storage::fake('local');
        Config::set('backup.backup.name', 'nrth');
        Config::set('app.name', 'nrth');
        $this->prepareMailConfig();

        $notification = new \App\Domain\Backup\Notifications\BackupWasSuccessfulNotification(
            new BackupWasSuccessful('local', 'nrth'),
        );

        $this->assertSame('nrth backup ready', $notification->toMail()->subject);
    }

    private function prepareMailConfig(): void
    {
        Config::set('backup.notifications.mail.from.address', 'backup@example.com');
        Config::set('backup.notifications.mail.from.name', 'nrth');
        SpatieBackupConfig::rebind();
        app()->forgetInstance(SpatieBackupConfig::class);
    }
}
