<?php

namespace Tests\Unit\Instance;

use App\Domain\Instance\Services\InstanceMailSettings;
use App\Mail\InstanceSmtpTestMail;
use App\Models\InstanceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstanceMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_props_never_expose_password(): void
    {
        app(InstanceMailSettings::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'tls',
            'username' => 'mailer',
            'password' => 'super-secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        $props = app(InstanceMailSettings::class)->publicProps();

        $this->assertTrue($props['enabled']);
        $this->assertTrue($props['password_set']);
        $this->assertTrue($props['using_instance']);
        $this->assertSame('smtp.example.com', $props['host']);
        $this->assertSame('noreply@example.com', $props['from_address']);
        $this->assertArrayNotHasKey('password', $props);
        $this->assertStringContainsString('smtp.example.com', $props['summary']);
    }

    public function test_blank_password_keeps_previous(): void
    {
        $settings = app(InstanceMailSettings::class);
        $settings->update([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'tls',
            'username' => 'mailer',
            'password' => 'first-secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        $settings->update([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 465,
            'scheme' => 'smtps',
            'username' => 'mailer',
            'password' => '',
            'from_address' => 'billing@example.com',
            'from_name' => 'nrth',
        ]);

        $current = $settings->current();
        $this->assertSame('first-secret', $current['password']);
        $this->assertSame(465, $current['port']);
        $this->assertSame('billing@example.com', $current['from_address']);

        $stored = InstanceSetting::query()->find(InstanceMailSettings::SETTING_KEY)?->value;
        $this->assertIsArray($stored);
        $this->assertSame('first-secret', Crypt::decryptString($stored['password_encrypted']));
    }

    public function test_apply_to_runtime_sets_smtp_when_enabled(): void
    {
        config(['mail.default' => 'log']);

        app(InstanceMailSettings::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'tls',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('tls', config('mail.mailers.smtp.scheme'));
        $this->assertSame('mailer', config('mail.mailers.smtp.username'));
        $this->assertSame('secret', config('mail.mailers.smtp.password'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
        $this->assertSame('nrth', config('mail.from.name'));
    }

    public function test_disabled_does_not_override_mail_default(): void
    {
        config(['mail.default' => 'log']);

        app(InstanceMailSettings::class)->update([
            'enabled' => false,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => '',
            'from_name' => '',
        ]);

        app(InstanceMailSettings::class)->applyToRuntime();

        $this->assertSame('log', config('mail.default'));
        $this->assertStringContainsString('.env', app(InstanceMailSettings::class)->publicProps()['summary']);
    }

    public function test_enabled_requires_host_and_from(): void
    {
        $this->expectException(ValidationException::class);

        app(InstanceMailSettings::class)->update([
            'enabled' => true,
            'host' => '',
            'port' => 587,
            'scheme' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => 'not-an-email',
            'from_name' => '',
        ]);
    }

    public function test_send_test_delivers_to_recipient(): void
    {
        Mail::fake();

        app(InstanceMailSettings::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'tls',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        app(InstanceMailSettings::class)->sendTest('operator@example.com');

        Mail::assertSent(InstanceSmtpTestMail::class, function (InstanceSmtpTestMail $mail): bool {
            return $mail->hasTo('operator@example.com');
        });
    }

    public function test_send_test_wraps_transport_failures(): void
    {
        Mail::shouldReceive('purgeMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        app(InstanceMailSettings::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'tls',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        try {
            app(InstanceMailSettings::class)->sendTest('operator@example.com');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('host', $e->errors());
            $this->assertStringContainsString('Could not send the test email', $e->errors()['host'][0]);
            $this->assertStringContainsString('Connection refused', $e->errors()['host'][0]);
        }
    }
}
