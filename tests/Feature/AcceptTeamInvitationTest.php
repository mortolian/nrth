<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Tests\TestCase;

class AcceptTeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_invitation_does_not_allow_self_registration(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $team->teamInvitations()->create([
            'email' => 'invitee@example.com',
            'role' => 'viewer',
        ]);

        $this->post('/register', [
            'name' => 'Invitee User',
            'email' => 'invitee@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $invitee = User::query()->where('email', 'invitee@example.com')->first();
        $this->assertNull($invitee);
        $this->assertCount(1, $team->fresh()->teamInvitations);
    }

    public function test_accepting_invitation_switches_team_and_skips_onboarding_for_members(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $invitee = User::factory()->create([
            'email' => 'member@example.com',
            'completed_onboarding_at' => null,
        ]);

        $invitation = $team->teamInvitations()->create([
            'email' => 'member@example.com',
            'role' => 'accountant',
        ]);

        $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

        $this->actingAs($invitee)
            ->get($url)
            ->assertRedirect(config('fortify.home'));

        $invitee->refresh();
        $this->assertTrue($invitee->belongsToTeam($team));
        $this->assertTrue($invitee->hasTeamRole($team, 'accountant'));
        $this->assertSame($team->id, $invitee->current_team_id);
        $this->assertNotNull($invitee->completed_onboarding_at);
        $this->assertFalse($invitee->ownedTeams()->exists());

        $this->actingAs($invitee)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_accept_with_existing_personal_team_does_not_403_on_onboarding(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $invitee = User::factory()->withPersonalTeam()->create([
            'email' => 'has-personal@example.com',
            'completed_onboarding_at' => null,
        ]);
        $personalTeamId = $invitee->currentTeam->id;

        $invitation = $team->teamInvitations()->create([
            'email' => 'has-personal@example.com',
            'role' => 'viewer',
        ]);

        $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

        $this->actingAs($invitee)
            ->get($url)
            ->assertRedirect(config('fortify.home'));

        $invitee->refresh();
        $this->assertSame($team->id, $invitee->current_team_id);
        $this->assertNotNull($invitee->completed_onboarding_at);
        $this->assertTrue($invitee->ownedTeams()->whereKey($personalTeamId)->exists());

        $this->actingAs($invitee)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($invitee)
            ->get(route('onboarding.setup'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_accept_rejects_mismatched_email(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;

        $other = User::factory()->create(['email' => 'other@example.com']);
        $invitation = $team->teamInvitations()->create([
            'email' => 'invitee@example.com',
            'role' => 'viewer',
        ]);

        $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

        $this->actingAs($other)
            ->get($url)
            ->assertRedirect(route('dashboard'));

        $this->assertStringContainsString('invitee@example.com', (string) session('error'));
    }

    public function test_join_link_redirects_new_invitees_to_login_with_help_message(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $invitation = $team->teamInvitations()->create([
            'email' => 'newbie@example.com',
            'role' => 'viewer',
        ]);

        $url = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);

        $this->get($url)
            ->assertRedirect(route('login'));

        $this->assertStringContainsString('No account exists for newbie@example.com yet.', (string) session('error'));
        $this->assertNull(session('invitation_join'));
    }

    public function test_login_with_pending_invitation_joins_and_skips_business_setup(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $invitee = User::factory()->withPersonalTeam()->create([
            'email' => 'pending-login@example.com',
            'password' => bcrypt('password'),
            'completed_onboarding_at' => null,
        ]);

        $team->teamInvitations()->create([
            'email' => 'pending-login@example.com',
            'role' => 'viewer',
        ]);

        $this->post('/login', [
            'email' => 'pending-login@example.com',
            'password' => 'password',
        ])->assertRedirect(config('fortify.home'));

        $invitee->refresh();
        $this->assertTrue($invitee->belongsToTeam($team));
        $this->assertSame($team->id, $invitee->current_team_id);
        $this->assertNotNull($invitee->completed_onboarding_at);

        $this->actingAs($invitee)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($invitee)
            ->get(route('onboarding.setup'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_join_link_sends_existing_users_to_login_then_accepts(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create([
            'completed_onboarding_at' => now(),
        ]);
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $existing = User::factory()->create([
            'email' => 'existing@example.com',
            'completed_onboarding_at' => now(),
        ]);

        $invitation = $team->teamInvitations()->create([
            'email' => 'existing@example.com',
            'role' => 'accountant',
        ]);

        $url = URL::signedRoute('team-invitations.join', ['invitation' => $invitation]);

        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs($existing)
            ->get($url)
            ->assertRedirect(config('fortify.home'));

        $existing->refresh();
        $this->assertTrue($existing->belongsToTeam($team));
        $this->assertSame($team->id, $existing->current_team_id);
    }
}
