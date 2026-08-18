<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Jetstream\Features as JetstreamFeatures;
use Tests\TestCase;

class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_name_can_be_updated_without_password(): void
    {
        $this->actingAs($user = User::factory()->create([
            'email' => 'keep@example.com',
        ]));

        $this->put('/user/profile-information', [
            'name' => 'Test Name',
            'email' => 'keep@example.com',
        ]);

        $this->assertEquals('Test Name', $user->fresh()->name);
        $this->assertEquals('keep@example.com', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_change_requires_current_password(): void
    {
        $this->actingAs($user = User::factory()->create([
            'email' => 'old@example.com',
        ]));

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertSessionHasErrors('current_password', errorBag: 'updateProfileInformation');

        $this->assertEquals('old@example.com', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_change_with_password_retrusts_a_safe_address(): void
    {
        $this->actingAs($user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => bcrypt('password'),
        ]));

        $this->put('/user/profile-information', [
            'name' => 'Test Name',
            'email' => 'new@example.com',
            'current_password' => 'password',
        ]);

        $user->refresh();
        $this->assertEquals('Test Name', $user->name);
        $this->assertEquals('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_change_does_not_claim_pending_invitations(): void
    {
        if (! JetstreamFeatures::sendsTeamInvitations()) {
            $this->markTestSkipped('Team invitations not enabled.');
        }

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $team->teamInvitations()->create([
            'email' => 'stolen@example.com',
            'role' => 'accountant',
        ]);

        $attacker = User::factory()->withPersonalTeam()->create([
            'email' => 'attacker@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($attacker)->put('/user/profile-information', [
            'name' => $attacker->name,
            'email' => 'stolen@example.com',
            'current_password' => 'password',
        ]);

        $attacker->refresh();
        $this->assertEquals('stolen@example.com', $attacker->email);
        $this->assertNull($attacker->email_verified_at);

        $this->actingAs($attacker)
            ->get(route('dashboard'))
            ->assertOk();

        $attacker->refresh();
        $this->assertFalse($attacker->belongsToTeam($team));
        $this->assertCount(1, $team->fresh()->teamInvitations);
    }

    public function test_email_change_to_env_operator_address_does_not_grant_access(): void
    {
        User::factory()->withPersonalTeam()->create();

        Config::set('nrth.operator_emails', ['ops@example.com']);

        $this->actingAs($user = User::factory()->withPersonalTeam()->create([
            'email' => 'staff@example.com',
            'password' => bcrypt('password'),
            'is_instance_operator' => false,
        ]));

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'ops@example.com',
            'current_password' => 'password',
        ]);

        $user->refresh();
        $this->assertEquals('ops@example.com', $user->email);
        $this->assertNull($user->email_verified_at);

        $this->get(route('settings.instance'))->assertForbidden();
    }
}
