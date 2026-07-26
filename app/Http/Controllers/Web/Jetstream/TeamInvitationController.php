<?php

namespace App\Http\Controllers\Web\Jetstream;

use App\Support\AcceptTeamInvitations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;

class TeamInvitationController extends Controller
{
    /**
     * Accept a team invitation and land on that business (no owner onboarding).
     *
     * @param  int  $invitationId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function accept(Request $request, $invitationId)
    {
        $model = Jetstream::teamInvitationModel();

        /** @var \App\Models\TeamInvitation $invitation */
        $invitation = $model::whereKey($invitationId)->firstOrFail();
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (strcasecmp(trim((string) $user->email), trim((string) $invitation->email)) !== 0) {
            return redirect()
                ->route('dashboard')
                ->with('error', __('Sign in with :email to accept this invitation.', [
                    'email' => $invitation->email,
                ]));
        }

        $team = AcceptTeamInvitations::acceptOne($user, $invitation);
        if ($team === null) {
            return redirect()
                ->route('dashboard')
                ->with('error', __('This invitation is no longer valid.'));
        }

        // Join any other pending invites for the same email.
        AcceptTeamInvitations::forUser($user->fresh());

        return redirect(config('fortify.home'))->with(
            'success',
            __('You have joined :team.', ['team' => $team->name]),
        );
    }

    /**
     * Cancel the given team invitation.
     *
     * @param  int  $invitationId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, $invitationId)
    {
        $model = Jetstream::teamInvitationModel();

        $invitation = $model::whereKey($invitationId)->firstOrFail();

        if (! Gate::forUser($request->user())->check('removeTeamMember', $invitation->team)) {
            throw new AuthorizationException;
        }

        $invitation->delete();

        return back(303);
    }
}
