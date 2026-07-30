<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\AcceptTeamInvitations;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JoinTeamInvitationController extends Controller
{
    /**
     * Single entry for invitation emails: join as a new or existing user.
     */
    public function __invoke(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->loadMissing('team');
        $team = $invitation->team;

        if ($team === null) {
            $invitation->delete();

            return redirect()
                ->route('login')
                ->with('error', __('This invitation is no longer valid.'));
        }

        EnsureTeamSystemRoles::ensureFor($team);

        $user = $request->user();

        if ($user !== null) {
            return $this->acceptAuthenticated($user, $invitation);
        }

        $email = strtolower(trim((string) $invitation->email));
        $accountExists = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();

        $inviteContext = [
            'email' => $invitation->email,
            'team_name' => $team->name,
            'role_label' => $this->roleLabel($invitation),
        ];

        if ($accountExists) {
            // After login, return to this signed URL and join automatically.
            $request->session()->put('url.intended', $request->fullUrl());
            $request->session()->put('invitation_join', $inviteContext);

            return redirect()->route('login');
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()
            ->route('login')
            ->with('error', __('No account exists for :email yet. Ask the instance administrator or business owner to create one before you sign in.', [
                'email' => $invitation->email,
            ]));
    }

    private function acceptAuthenticated(User $user, TeamInvitation $invitation): RedirectResponse
    {
        if (strcasecmp(trim((string) $user->email), trim((string) $invitation->email)) !== 0) {
            return redirect()
                ->route('dashboard')
                ->with('error', __('This invitation was sent to :email. Sign in with that address to join.', [
                    'email' => $invitation->email,
                ]));
        }

        $team = AcceptTeamInvitations::acceptOne($user, $invitation);
        if ($team === null) {
            return redirect()
                ->route('dashboard')
                ->with('error', __('This invitation is no longer valid.'));
        }

        AcceptTeamInvitations::forUser($user->fresh());
        $requestSession = request()->session();
        $requestSession->forget('invitation_join');

        return redirect(config('fortify.home'))->with(
            'success',
            __('You have joined :team.', ['team' => $team->name]),
        );
    }

    private function roleLabel(TeamInvitation $invitation): string
    {
        $team = $invitation->team;
        if ($team === null) {
            return (string) $invitation->role;
        }

        $role = $team->teamRoles()->where('key', $invitation->role)->first();
        if ($role !== null) {
            $preset = RolePresets::systemRoles()[$role->key] ?? null;

            return $preset['name'] ?? $role->name;
        }

        return ucfirst((string) $invitation->role);
    }
}
