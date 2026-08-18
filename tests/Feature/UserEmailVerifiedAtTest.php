<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class UserEmailVerifiedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_create_persists_verified_at_even_when_not_fillable(): void
    {
        $user = User::query()->create([
            'name' => 'Installer',
            'email' => 'install@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_create_without_verified_at_defaults_to_now(): void
    {
        $user = User::query()->create([
            'name' => 'Signup',
            'email' => 'signup@example.com',
            'password' => 'password',
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_factory_state_is_preserved(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertNull($user->email_verified_at);
    }

    public function test_create_new_user_marks_email_verified(): void
    {
        $user = (new CreateNewUser)->create([
            'name' => 'Fortify User',
            'email' => 'fortify@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_env_operator_email_works_for_users_created_via_query_create(): void
    {
        User::factory()->withPersonalTeam()->create();

        Config::set('nrth.operator_emails', ['env-create@example.com']);

        $user = User::query()->create([
            'name' => 'Env Create',
            'email' => 'env-create@example.com',
            'password' => 'password',
            'completed_onboarding_at' => now(),
        ]);
        $user->ownedTeams()->create([
            'name' => "Env Create's Team",
            'personal_team' => true,
        ]);
        $user->forceFill([
            'current_team_id' => $user->ownedTeams()->value('id'),
            'is_instance_operator' => false,
        ])->save();

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->actingAs($user->fresh());
        $this->get(route('settings.instance'))->assertOk();
    }
}
