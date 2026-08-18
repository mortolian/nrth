<?php

namespace Tests\Feature\Settings;

use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionIdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_settings_persists_idle_timeout_setting(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->put(
            route('settings.team.session-idle-timeout'),
            ['session_idle_timeout_minutes' => 30]
        )->assertRedirect();

        $this->assertSame(30, (int) $team->fresh()->mergedBusinessSettings()['session_idle_timeout_minutes']);
    }

    public function test_idle_timeout_cannot_exceed_session_lifetime(): void
    {
        config(['session.lifetime' => 120]);

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->put(
            route('settings.team.session-idle-timeout'),
            ['session_idle_timeout_minutes' => 121]
        )->assertSessionHasErrors('session_idle_timeout_minutes');

        $this->assertSame(0, (int) $team->fresh()->mergedBusinessSettings()['session_idle_timeout_minutes']);
    }

    public function test_request_past_idle_window_logs_user_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['session_idle_timeout_minutes' => 15]
            ),
        ])->save();

        $this->actingAs($user);

        $stale = now()->subMinutes(16)->getTimestamp();

        $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => $stale,
        ])->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were signed out due to inactivity.');

        $this->assertGuest();
    }

    public function test_idle_timeout_applies_to_profile_routes(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['session_idle_timeout_minutes' => 15]
            ),
        ])->save();

        $this->actingAs($user);

        $stale = now()->subMinutes(16)->getTimestamp();

        $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => $stale,
        ])->get(route('profile.show'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were signed out due to inactivity.');

        $this->assertGuest();
    }

    public function test_request_within_idle_window_refreshes_activity(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                ['session_idle_timeout_minutes' => 15]
            ),
        ])->save();

        $this->actingAs($user);

        $recent = now()->subMinutes(5)->getTimestamp();

        $response = $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => $recent,
        ])->get(route('dashboard'));

        $response->assertOk();
        $this->assertAuthenticatedAs($user);

        $updated = session(EnforceSessionIdleTimeout::SESSION_KEY);
        $this->assertIsNumeric($updated);
        $this->assertGreaterThan($recent, (int) $updated);
    }

    public function test_idle_middleware_is_noop_when_timeout_disabled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->assertSame(0, (int) $team->mergedBusinessSettings()['session_idle_timeout_minutes']);

        $this->actingAs($user);
        $this->withSession([
            EnforceSessionIdleTimeout::SESSION_KEY => now()->subDays(2)->getTimestamp(),
        ])->get(route('dashboard'))->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_only_team_owner_can_update_idle_timeout(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        $team->users()->attach($member, ['role' => 'accountant']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($member);

        $this->put(
            route('settings.team.session-idle-timeout'),
            ['session_idle_timeout_minutes' => 30]
        )->assertForbidden();
    }
}
