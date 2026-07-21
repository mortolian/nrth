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

    public function test_operator_can_view_instance_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('settings.instance'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Instance')
                ->has('operators')
                ->has('backup_schedule_hint'));
    }

    public function test_non_operator_cannot_view_instance_settings(): void
    {
        Config::set('nrth.operator_emails', []);

        $first = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->withPersonalTeam()->create([
            'is_instance_operator' => false,
        ]);
        // Ensure first still has the flag from bootstrap; member does not.
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

        $response->assertRedirect(route('settings.instance'));
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

        $response->assertRedirect(route('settings.instance'));
        $this->assertFalse($other->fresh()->is_instance_operator);
    }

    public function test_env_email_grants_manage_access_without_flag(): void
    {
        Config::set('nrth.operator_emails', ['breakglass@example.com']);

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'breakglass@example.com',
            'is_instance_operator' => false,
        ]);
        // Another user may already be first-operator; force this one off.
        $user->forceFill(['is_instance_operator' => false])->save();

        $this->actingAs($user->fresh());
        $this->get(route('settings.instance'))->assertOk();
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
