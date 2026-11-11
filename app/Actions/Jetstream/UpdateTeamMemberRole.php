<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Rules\TeamRoleKey;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\TeamMemberUpdated;
use Laravel\Jetstream\Jetstream;

class UpdateTeamMemberRole
{
    /**
     * Update the role for the given team member.
     *
     * @param  mixed  $user
     * @param  mixed  $team
     * @param  int  $teamMemberId
     * @return void
     */
    public function update($user, $team, $teamMemberId, string $role)
    {
        Gate::forUser($user)->authorize('updateTeamMember', $team);

        Validator::make([
            'role' => $role,
        ], [
            'role' => ['required', 'string', new TeamRoleKey($team)],
        ])->validate();

        $member = Jetstream::findUserByIdOrFail($teamMemberId);
        if ($team instanceof Team && $member->isBusinessOwner($team)) {
            throw ValidationException::withMessages([
                'role' => __('Owners are changed from the owners section.'),
            ]);
        }

        $team->users()->updateExistingPivot($teamMemberId, [
            'role' => $role,
        ]);

        TeamMemberUpdated::dispatch($team->fresh(), Jetstream::findUserByIdOrFail($teamMemberId));
    }
}
