<?php

namespace App\Http\Controllers\Web\Jetstream;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Laravel\Jetstream\Http\Controllers\Inertia\TeamMemberController as JetstreamTeamMemberController;
use Laravel\Jetstream\Jetstream;

class TeamMemberController extends JetstreamTeamMemberController
{
    /**
     * Remove the given user from the given team (including self-leave).
     *
     * @param  int  $teamId
     * @param  int  $userId
     * @return RedirectResponse
     */
    public function destroy(Request $request, $teamId, $userId)
    {
        $team = Jetstream::newTeamModel()->findOrFail($teamId);

        app(RemovesTeamMembers::class)->remove(
            $request->user(),
            $team,
            $user = Jetstream::findUserByIdOrFail($userId)
        );

        if ($request->user()->id === $user->id) {
            $message = __('You left :team.', ['team' => $team->name]);
            $user = $user->fresh();

            if ($user === null || $user->currentTeam === null) {
                return redirect()->route('teams.create')->with('info', $message.' '.__('Create or join a business to continue.'));
            }

            return redirect(config('fortify.home'))->with('success', $message);
        }

        return back(303)->with('success', __('Access revoked for :name.', ['name' => $user->name]));
    }
}
