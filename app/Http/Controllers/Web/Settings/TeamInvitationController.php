<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Support\MailDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

class TeamInvitationController extends Controller
{
    public function resend(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $user = $request->user();
        $team = $invitation->team;

        abort_unless($team !== null && $user->belongsToTeam($team), 403);
        Gate::forUser($user)->authorize('addTeamMember', $team);

        if ($team->hasUserWithEmail($invitation->email)) {
            $invitation->delete();

            return back()->with('error', __('That person already belongs to this team. The invitation was removed.'));
        }

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));

        [$flashKey, $flashMessage] = MailDelivery::invitationSentFlash($invitation->email);

        return back()->with($flashKey, $flashMessage);
    }
}
