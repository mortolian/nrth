<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    public function test_operator_instance_settings_redirects_to_backups_exports(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('settings.instance'))
            ->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
    }

    public function test_operator_sees_operators_on_backups_exports(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('backups-exports.index', ['section' => 'backup']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('BackupsExports/Index')
                ->where('can_manage_backups', true)
                ->where('section', 'backup')
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

        $response->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
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

        $response->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
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
            ->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
    }

    public function test_operator_sees_backup_retention_on_backups_exports(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('backups-exports.index', ['section' => 'backup']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('BackupsExports/Index')
                ->where('backup_retention.keep_all_backups_for_days', 7)
                ->where('backup_retention.keep_weekly_backups_for_weeks', 8)
                ->where('backup_retention.keep_monthly_backups_for_months', 4)
                ->where('backup_retention.delete_oldest_backups_when_using_more_megabytes_than', 5000));
    }

    public function test_operator_can_update_backup_retention(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $response = $this->put(route('settings.instance.backup-retention.update'), [
            'keep_all_backups_for_days' => 3,
            'keep_daily_backups_for_days' => 10,
            'keep_weekly_backups_for_weeks' => 6,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 3,
            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ]);

        $response->assertRedirect(route('backups-exports.index', ['section' => 'backup']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('instance_settings', [
            'key' => 'backup.cleanup',
        ]);

        $this->assertSame(3, (int) config('backup.cleanup.default_strategy.keep_all_backups_for_days'));
        $this->assertSame(12, (int) config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'));
        $this->assertSame(2000, (int) config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than'));
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
            'keep_all_backups_for_days' => 3,
            'keep_daily_backups_for_days' => 10,
            'keep_weekly_backups_for_weeks' => 6,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 3,
            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ])->assertForbidden();
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
