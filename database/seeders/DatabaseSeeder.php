<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'completed_onboarding_at' => now(),
                'password' => Hash::make('password'),
                'remember_token' => Str::random(10),
            ],
        );

        if (Features::hasTeamFeatures()) {
            $team = $user->ownedTeams()->firstOrCreate(
                ['personal_team' => true],
                ['name' => "{$user->name}'s Team"],
            );

            if ($user->current_team_id !== $team->id) {
                $user->forceFill(['current_team_id' => $team->id])->save();
            }
        }
    }
}
