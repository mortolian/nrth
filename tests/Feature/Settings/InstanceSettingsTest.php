<?php

namespace Tests\Feature\Settings;

use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Instance\Services\InstanceMailSettings;
use App\Domain\Instance\Services\InstanceTimezoneSettings;
use App\Mail\BusinessOwnerAssignedMail;
use App\Mail\InstanceSmtpTestMail;
use App\Mail\TeamInvitationMail;
use App\Models\User;
use App\Support\TeamAccess\TeamAccess;
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
                ->has('timezone_summary')
                ->has('operators_summary'));
    }

    public function test_operator_can_list_and_manage_all_teams(): void
    {
        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $otherOwner = User::factory()->withPersonalTeam()->create();
        $otherTeam = $otherOwner->currentTeam;

        $this->actingAs($operator)
            ->get(route('settings.instance.teams'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance/Teams/Index')
                ->has('teams', 2));

        $this->actingAs($operator)
            ->get(route('settings.instance.teams.show', $otherTeam))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance/Teams/Manage')
                ->where('team.id', $otherTeam->id)
                ->has('owners', 1)
                ->where('owners.0.email', $otherOwner->email)
                ->has('members')
                ->has('roles')
                ->has('permission_groups'));

        $this->actingAs($operator)
            ->get(route('settings.instance.teams.members', $otherTeam))
            ->assertRedirect(route('settings.instance.teams.show', $otherTeam));
    }

    public function test_operator_manages_members_and_roles_on_another_business(): void
    {
        Mail::fake();

        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $team->teamInvitations()->create([
            'email' => 'pending@northwind.example',
            'role' => 'viewer',
        ]);
        $invitation = $team->teamInvitations()->first();
        $this->assertNotNull($invitation);

        $this->actingAs($operator)
            ->post(route('team-invitations.resend', $invitation))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(TeamInvitationMail::class, fn (TeamInvitationMail $mail): bool => $mail->hasTo('pending@northwind.example'));

        $this->actingAs($operator)
            ->post(route('settings.instance.teams.members.store', $team), [
                'name' => 'Sam Lee',
                'email' => 'sam@northwind.example',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'role' => 'viewer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertNotSent(BusinessOwnerAssignedMail::class);

        $sam = User::query()->where('email', 'sam@northwind.example')->first();
        $this->assertNotNull($sam);
        $this->assertTrue(password_verify('new-password', $sam->password));
        $this->assertTrue($sam->belongsToTeam($team));
        $this->assertSame('viewer', $team->users()->where('users.id', $sam->id)->first()?->membership->role);
        $this->assertFalse($sam->isBusinessOwner($team));

        $this->actingAs($operator)
            ->put(route('settings.instance.teams.members.update', [$team, $sam]), [
                'role' => 'accountant',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('accountant', $team->users()->where('users.id', $sam->id)->first()?->membership->role);

        $this->actingAs($operator)
            ->post(route('settings.team.roles.store', $team), [
                'name' => 'Bookkeeper',
                'description' => 'Books only',
                'permissions' => ['invoices.view'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('team_roles', [
            'team_id' => $team->id,
            'name' => 'Bookkeeper',
        ]);

        $this->actingAs($operator)
            ->delete(route('settings.instance.teams.members.destroy', [$team, $sam]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($sam->fresh()->belongsToTeam($team));
        $this->assertNotNull($sam->fresh());

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)
            ->post(route('settings.instance.teams.members.store', $team), [
                'name' => 'Pat Lee',
                'email' => 'pat@northwind.example',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'role' => 'viewer',
            ])
            ->assertForbidden();
    }

    public function test_operator_adds_an_owner_and_sends_an_ownership_notice(): void
    {
        Mail::fake();

        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $team = $operator->currentTeam;

        $this->actingAs($operator)
            ->post(route('settings.instance.teams.owners.store', $team), [
                'name' => 'Sam Lee',
                'email' => 'sam@northwind.example',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(BusinessOwnerAssignedMail::class, function (BusinessOwnerAssignedMail $mail) use ($team): bool {
            $html = $mail->render();

            return $mail->hasTo('sam@northwind.example')
                && $mail->newAccount
                && $mail->team->is($team)
                && str_contains($html, $team->name)
                && str_contains($html, 'assigned as an owner')
                && ! str_contains($html, 'new-password');
        });
        Mail::assertNotSent(TeamInvitationMail::class);

        $sam = User::query()->where('email', 'sam@northwind.example')->first();
        $this->assertNotNull($sam);
        $this->assertTrue(password_verify('new-password', $sam->password));
        $this->assertNotNull($sam->completed_onboarding_at);
        $this->assertTrue($sam->isBusinessOwner($team));
        $this->assertFalse($sam->ownsTeam($team));
        $this->assertTrue(TeamAccess::allows($sam, $team, 'settings.team'));

        $existing = User::factory()->create([
            'email' => 'riley@northwind.example',
            'password' => 'already-set',
        ]);
        Mail::fake();

        $this->actingAs($operator)
            ->post(route('settings.instance.teams.owners.store', $team), [
                'name' => 'Riley Chen',
                'email' => 'riley@northwind.example',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(BusinessOwnerAssignedMail::class, function (BusinessOwnerAssignedMail $mail) use ($existing): bool {
            return $mail->hasTo('riley@northwind.example')
                && ! $mail->newAccount
                && $mail->user->is($existing)
                && str_contains($mail->render(), 'existing password');
        });
        $this->assertTrue(password_verify('already-set', $existing->fresh()->password));

        $this->actingAs($operator)
            ->delete(route('settings.instance.teams.owners.destroy', [$team, $operator]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($operator->fresh()->ownsTeam($team->fresh()));
        $this->assertTrue($sam->fresh()->ownsTeam($team->fresh()));

        $riley = User::query()->where('email', 'riley@northwind.example')->first();
        $this->actingAs($operator)
            ->delete(route('settings.instance.teams.owners.destroy', [$team, $riley]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($operator)
            ->from(route('settings.instance.teams.show', $team))
            ->delete(route('settings.instance.teams.owners.destroy', [$team, $sam]))
            ->assertRedirect()
            ->assertSessionHasErrors('owner');

        $this->assertTrue($sam->fresh()->ownsTeam($team->fresh()));
    }

    public function test_operator_can_update_another_business_owners_sign_in(): void
    {
        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $owner = User::factory()->withPersonalTeam()->create([
            'name' => 'Alex North',
            'email' => 'sample@northwind.example',
            'password' => 'password',
        ]);
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($operator)
            ->put(route('settings.instance.teams.owners.update', [$team, $owner]), [
                'name' => 'Alex Studio',
                'email' => 'studio@northwind.example',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $owner->refresh();
        $this->assertSame('Alex Studio', $owner->name);
        $this->assertSame('studio@northwind.example', $owner->email);
        $this->assertTrue(password_verify('new-password', $owner->password));
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame($operator->email, $operator->fresh()->email);
    }

    public function test_operator_can_rename_an_owner_without_changing_the_password(): void
    {
        $operator = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $owner = User::factory()->withPersonalTeam()->create([
            'password' => 'password',
        ]);

        $this->actingAs($operator)
            ->put(route('settings.instance.teams.owners.update', [$owner->currentTeam, $owner]), [
                'name' => 'Renamed Owner',
                'email' => $owner->email,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $owner->refresh();
        $this->assertSame('Renamed Owner', $owner->name);
        $this->assertTrue(password_verify('password', $owner->password));
    }

    public function test_non_operator_cannot_update_an_owner(): void
    {
        User::factory()->withPersonalTeam()->create();
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        $user->forceFill(['is_instance_operator' => false])->save();
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->put(route('settings.instance.teams.owners.update', [$owner->currentTeam, $owner]), [
                'name' => 'Hijack',
                'email' => 'hijack@example.test',
            ])
            ->assertForbidden();

        $this->assertNotSame('Hijack', $owner->fresh()->name);
    }

    public function test_non_operator_cannot_access_instance_teams(): void
    {
        User::factory()->withPersonalTeam()->create();
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        $user->forceFill(['is_instance_operator' => false])->save();
        $this->actingAs($user);

        $this->get(route('settings.instance.teams'))->assertForbidden();
    }

    public function test_operator_can_update_instance_timezone(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('settings.instance.timezone'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance/Timezone')
                ->has('timezone')
                ->has('timezone_options')
                ->has('timezone_options.0.value')
                ->missing('timezone_options.100'));

        $this->put(route('settings.instance.timezone.update'), [
            'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect(route('settings.instance.timezone'));

        $this->assertSame(
            'Africa/Johannesburg',
            app(InstanceTimezoneSettings::class)->resolved()
        );
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

    public function test_unverified_env_operator_email_cannot_manage_instance(): void
    {
        User::factory()->withPersonalTeam()->create();

        Config::set('nrth.operator_emails', ['env-op@example.com']);

        $user = User::factory()->withPersonalTeam()->unverified()->create([
            'email' => 'env-op@example.com',
            'is_instance_operator' => false,
        ]);

        $this->actingAs($user);
        $this->get(route('settings.instance'))->assertForbidden();
    }

    public function test_verified_env_operator_email_can_manage_instance(): void
    {
        User::factory()->withPersonalTeam()->create();

        Config::set('nrth.operator_emails', ['env-op@example.com']);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'env-op@example.com',
            'is_instance_operator' => false,
        ]);

        $this->actingAs($user);
        $this->get(route('settings.instance'))->assertOk();
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
