<?php

namespace App\Domain\Instance\Services;

use App\Models\Team;
use App\Models\User;
use App\Rules\TeamRoleKey;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BusinessMembers
{
    /**
     * Create or attach a member with a role. No invitation email is sent.
     */
    public function add(Team $team, string $name, string $email, ?string $password, string $role): User
    {
        EnsureTeamSystemRoles::ensureFor($team);

        Validator::make([
            'role' => $role,
        ], [
            'role' => ['required', 'string', new TeamRoleKey($team)],
        ])->validate();

        $email = trim($email);
        $existing = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();

        if ($existing !== null && $existing->isBusinessOwner($team)) {
            throw ValidationException::withMessages([
                'email' => __('That person is an owner. Edit them in the owners section.'),
            ]);
        }

        if ($existing !== null && $team->users()->where('users.id', $existing->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('That person is already a member of this business.'),
            ]);
        }

        if ($existing === null) {
            if (! filled($password)) {
                throw ValidationException::withMessages([
                    'password' => __('Choose a password for this member.'),
                ]);
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'completed_onboarding_at' => now(),
            ])->save();
        } else {
            $existing->name = $name;
            if (filled($password)) {
                $existing->password = $password;
            }
            $existing->save();
            $user = $existing;
        }

        $team->users()->attach($user->id, ['role' => $role]);
        $team->teamInvitations()->whereRaw('lower(email) = ?', [strtolower($user->email)])->delete();

        $user->refresh();
        if ($user->current_team_id === null) {
            $user->switchTeam($team->fresh());
        }

        return $user;
    }
}
