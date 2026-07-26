<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Jetstream\Features;
use Tests\TestCase;

class InviteTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_members_can_be_invited_to_team(): void
    {
        if (! Features::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        Mail::fake();

        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->post('/teams/'.$user->currentTeam->id.'/members', [
            'email' => 'test@example.com',
            'role' => 'accountant',
        ]);

        Mail::assertSent(TeamInvitationMail::class);

        $this->assertCount(1, $user->currentTeam->fresh()->teamInvitations);
    }

    public function test_team_member_invitations_can_be_cancelled(): void
    {
        if (! Features::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        Mail::fake();

        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $invitation = $user->currentTeam->teamInvitations()->create([
            'email' => 'test@example.com',
            'role' => 'accountant',
        ]);

        $this->delete('/team-invitations/'.$invitation->id);

        $this->assertCount(0, $user->currentTeam->fresh()->teamInvitations);
    }

    public function test_pending_invitations_can_be_resent(): void
    {
        if (! Features::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        Mail::fake();

        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $invitation = $user->currentTeam->teamInvitations()->create([
            'email' => 'test@example.com',
            'role' => 'accountant',
        ]);

        $this->post(route('team-invitations.resend', $invitation))
            ->assertRedirect();

        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) use ($invitation) {
            return $mail->invitation->is($invitation);
        });

        $this->assertCount(1, $user->currentTeam->fresh()->teamInvitations);
    }

    public function test_only_team_owner_can_resend_invitations(): void
    {
        if (! Features::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        Mail::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'accountant']);

        $invitation = $owner->currentTeam->teamInvitations()->create([
            'email' => 'pending@example.com',
            'role' => 'viewer',
        ]);

        $this->actingAs($member)
            ->post(route('team-invitations.resend', $invitation))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_invitation_warns_when_mailer_is_log(): void
    {
        if (! Features::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        Mail::fake();
        config(['mail.default' => 'log']);

        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->post('/teams/'.$user->currentTeam->id.'/members', [
            'email' => 'test@example.com',
            'role' => 'accountant',
        ])->assertRedirect();

        $this->assertTrue(session()->has('warning'));
        $this->assertStringContainsString('log', (string) session('warning'));
    }
}
