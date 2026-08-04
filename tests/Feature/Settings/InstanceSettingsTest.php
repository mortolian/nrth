<?php

namespace Tests\Feature\Settings;

use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Instance\Services\InstanceMailSettings;
use App\Mail\InstanceSmtpTestMail;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InstanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_first_user_is_promoted_to_instance_operator(): void
    {
        $first = User::factory()->withPersonalTeam()->create();
        $second = User::factory()->withPersonalTeam()->create();

        $this->assertTrue($first->fresh()->is_instance_operator);
        $this->assertFalse($second->fresh()->is_instance_operator);
    }

    public function test_operator_sees_instance_settings_hub(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('settings.instance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance/Index')
                ->has('mail_summary')
                ->has('operators_summary'));
    }

    public function test_operator_sees_operators_on_operators_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('settings.instance.operators'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance/Operators')
                ->has('operators')
                ->has('env_break_glass_configured'));
    }

    public function test_non_operator_cannot_view_instance_settings(): void
    {
        Config::set('nrth.operator_emails', []);

        $first = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        $this->assertTrue($first->fresh()->is_instance_operator);

        $this->actingAs($member);
        $this->get(route('settings.instance'))->assertForbidden();
    }

    public function test_operator_can_add_another_operator_by_email(): void
    {
        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $other = User::factory()->withPersonalTeam()->create([
            'email' => 'colleague@example.com',
            'is_instance_operator' => false,
        ]);

        $this->actingAs($operator);
        $response = $this->post(route('settings.instance.operators.store'), [
            'email' => 'colleague@example.com',
        ]);

        $response->assertRedirect(route('settings.instance.operators'));
        $this->assertTrue($other->fresh()->is_instance_operator);
    }

    public function test_cannot_remove_last_database_operator_without_env_break_glass(): void
    {
        Config::set('nrth.operator_emails', []);

        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);

        $this->actingAs($operator);
        $response = $this->delete(route('settings.instance.operators.destroy', $operator));

        $response->assertSessionHasErrors('user_id');
        $this->assertTrue($operator->fresh()->is_instance_operator);
    }

    public function test_can_remove_operator_when_another_remains(): void
    {
        Config::set('nrth.operator_emails', []);

        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $other = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);

        $this->actingAs($operator);
        $response = $this->delete(route('settings.instance.operators.destroy', $other));

        $response->assertRedirect(route('settings.instance.operators'));
        $this->assertFalse($other->fresh()->is_instance_operator);
    }

    public function test_env_email_grants_manage_access_without_flag(): void
    {
        Config::set('nrth.operator_emails', ['breakglass@example.com']);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'breakglass@example.com',
            'is_instance_operator' => false,
        ]);
        $user->forceFill(['is_instance_operator' => false])->save();

        $this->actingAs($user->fresh());
        $this->get(route('settings.instance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Instance/Index'));
    }

    public function test_operator_sees_backup_retention_on_retention_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('backups-exports.retention'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('BackupsExports/Retention')
                ->where('backup_retention.keep_daily', 7)
                ->where('backup_retention.keep_weekly', 8)
                ->where('backup_retention.keep_monthly', 4)
                ->where('backup_retention.weekly_on', 'sunday')
                ->where('backup_retention.delete_oldest_backups_when_using_more_megabytes_than', 5000));

        $this->get(route('backups-exports.destinations'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('BackupsExports/Destinations')
                ->where('backup_destinations.s3.enabled', false)
                ->where('backup_destinations.path.enabled', false)
                ->where('backup_destinations.active_disks', ['local']));
    }

    public function test_operator_can_update_backup_retention(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $response = $this->put(route('settings.instance.backup-retention.update'), [
            'keep_daily' => 3,
            'keep_weekly' => 6,
            'keep_monthly' => 12,
            'keep_yearly' => 3,
            'weekly_on' => 'monday',
            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ]);

        $response->assertRedirect(route('backups-exports.retention'));
        $response->assertSessionHas('success');

        $current = app(InstanceBackupRetentionSettings::class)->current();
        $this->assertSame(3, $current['keep_daily']);
        $this->assertSame(12, $current['keep_monthly']);
        $this->assertSame('monday', $current['weekly_on']);
        $this->assertSame(2000, $current['delete_oldest_backups_when_using_more_megabytes_than']);
    }

    public function test_operator_can_update_backup_destinations(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $root = storage_path('framework/testing/backup-offsite-feature');
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $response = $this->put(route('settings.instance.backup-destinations.update'), [
            's3' => [
                'enabled' => true,
                'key' => 'AKIATEST',
                'secret' => 'secret-value',
                'region' => 'eu-west-1',
                'bucket' => 'nrth-backups',
                'endpoint' => '',
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => [
                'enabled' => true,
                'root' => $root,
            ],
        ]);

        $response->assertRedirect(route('backups-exports.destinations'));
        $response->assertSessionHas('success');

        $props = app(InstanceBackupDestinationSettings::class)->publicProps();
        $this->assertTrue($props['s3']['enabled']);
        $this->assertTrue($props['path']['enabled']);
        $this->assertTrue($props['s3']['secret_set']);
        $this->assertContains('backup_s3', $props['active_disks']);
        $this->assertContains('backup_path', $props['active_disks']);
    }

    public function test_non_operator_cannot_update_backup_destinations(): void
    {
        Config::set('nrth.operator_emails', []);

        $first = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        $this->assertTrue($first->fresh()->is_instance_operator);

        $this->actingAs($member);
        $this->put(route('settings.instance.backup-destinations.update'), [
            's3' => [
                'enabled' => true,
                'key' => 'AKIATEST',
                'secret' => 'secret',
                'region' => 'us-east-1',
                'bucket' => 'x',
                'endpoint' => '',
                'use_path_style_endpoint' => false,
                'root' => '',
            ],
            'path' => [
                'enabled' => false,
                'root' => '',
            ],
        ])->assertForbidden();
    }

    public function test_non_operator_cannot_update_backup_retention(): void
    {
        Config::set('nrth.operator_emails', []);

        $first = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        $this->assertTrue($first->fresh()->is_instance_operator);

        $this->actingAs($member);
        $this->put(route('settings.instance.backup-retention.update'), [
            'keep_daily' => 3,
            'keep_weekly' => 6,
            'keep_monthly' => 12,
            'keep_yearly' => 3,
            'weekly_on' => 'monday',
            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ])->assertForbidden();
    }

    public function test_operator_sees_mail_settings_page(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('settings.instance.mail'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance/Mail')
                ->where('mail.enabled', false)
                ->where('mail.password_set', false)
                ->where('mail.using_instance', false)
                ->where('test_to_default', $user->email));

        $this->get(route('backups-exports.mail'))
            ->assertRedirect('/settings/instance/mail');
    }

    public function test_operator_can_update_mail_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $response = $this->put(route('settings.instance.mail.update'), [
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        $response->assertRedirect(route('settings.instance.mail'));
        $response->assertSessionHas('success');

        $props = app(InstanceMailSettings::class)->publicProps();
        $this->assertTrue($props['enabled']);
        $this->assertTrue($props['password_set']);
        $this->assertSame('smtp.example.com', $props['host']);
        $this->assertSame('smtp', config('mail.default'));
    }

    public function test_operator_can_send_test_mail(): void
    {
        Mail::fake();

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'operator@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->put(route('settings.instance.mail.update'), [
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ])->assertRedirect(route('settings.instance.mail'));

        $response = $this->post(route('settings.instance.mail.test'), [
            'to' => 'elsewhere@example.com',
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'mailer',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ]);

        $response->assertRedirect(route('settings.instance.mail'));
        $response->assertSessionHas('success', 'Test email sent to elsewhere@example.com.');
        Mail::assertSent(InstanceSmtpTestMail::class, function ($mail) {
            return $mail->hasTo('elsewhere@example.com');
        });
    }

    public function test_operator_sees_error_when_test_mail_fails(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'operator@example.com',
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->put(route('settings.instance.mail.update'), [
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ])->assertRedirect(route('settings.instance.mail'));

        Mail::shouldReceive('purgeMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $response = $this->from(route('settings.instance.mail'))
            ->post(route('settings.instance.mail.test'), [
                'host' => 'smtp.example.com',
                'port' => 587,
                'scheme' => 'smtp',
                'username' => 'mailer',
                'from_address' => 'noreply@example.com',
                'from_name' => 'nrth',
            ]);

        $response->assertRedirect(route('settings.instance.mail'));
        $response->assertSessionHasErrors('host');
        $this->assertStringContainsString(
            'Could not send the test email',
            session('errors')->first('host'),
        );
    }

    public function test_non_operator_cannot_update_mail_settings(): void
    {
        Config::set('nrth.operator_emails', []);

        $first = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        $this->assertTrue($first->fresh()->is_instance_operator);

        $this->actingAs($member);
        $this->put(route('settings.instance.mail.update'), [
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => 'mailer',
            'password' => 'secret',
            'from_address' => 'noreply@example.com',
            'from_name' => 'nrth',
        ])->assertForbidden();

        $this->get(route('settings.instance.mail'))->assertForbidden();
    }

    public function test_promote_first_operator_command(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $user->forceFill(['is_instance_operator' => false])->save();

        $this->artisan('nrth:promote-first-operator')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_instance_operator);

        $this->artisan('nrth:promote-first-operator')
            ->assertSuccessful();
    }
}
