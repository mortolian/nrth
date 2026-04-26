<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Jetstream\Jetstream;

class TeamSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_unless($team !== null && $user->belongsToTeam($team), 403);

        Gate::authorize('view', $team);

        $team->loadMissing(['owner', 'users', 'teamInvitations']);

        $members = collect();

        $owner = $team->owner;
        if ($owner !== null) {
            $members->push([
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'profile_photo_url' => $owner->profile_photo_url,
                'role_key' => 'owner',
                'role_label' => 'Owner',
                'is_owner' => true,
            ]);
        }

        foreach ($team->users as $memberUser) {
            if ($owner !== null && $memberUser->id === $owner->id) {
                continue;
            }
            $roleKey = $memberUser->membership->role ?? 'viewer';
            $roleMeta = Jetstream::findRole($roleKey);
            $members->push([
                'id' => $memberUser->id,
                'name' => $memberUser->name,
                'email' => $memberUser->email,
                'profile_photo_url' => $memberUser->profile_photo_url,
                'role_key' => $roleKey,
                'role_label' => $roleMeta?->name ?? ucfirst($roleKey),
                'is_owner' => false,
            ]);
        }

        $invitations = $team->teamInvitations->map(function ($invitation) {
            $roleMeta = Jetstream::findRole((string) $invitation->role);

            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_key' => $invitation->role,
                'role_label' => $roleMeta?->name ?? (string) $invitation->role,
            ];
        })->values()->all();

        return Inertia::render('Settings/Team', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'personal_team' => (bool) $team->personal_team,
            ],
            'members' => $members->values()->all(),
            'invitations' => $invitations,
            'available_roles' => array_values(Jetstream::$roles),
            'permissions' => [
                'canAddTeamMembers' => Gate::check('addTeamMember', $team),
                'canDeleteTeam' => Gate::check('delete', $team),
                'canRemoveTeamMembers' => Gate::check('removeTeamMember', $team),
                'canUpdateTeam' => Gate::check('update', $team),
                'canUpdateTeamMembers' => Gate::check('updateTeamMember', $team),
            ],
            'role_summaries' => [
                [
                    'key' => 'owner',
                    'title' => 'Owner',
                    'description' => 'Full access to all features, billing, and team management.',
                ],
                [
                    'key' => 'accountant',
                    'title' => 'Accountant',
                    'description' => 'View and manage data, export reports. Cannot delete transactions.',
                ],
                [
                    'key' => 'viewer',
                    'title' => 'Viewer',
                    'description' => 'Read-only access to dashboards and reports.',
                ],
            ],
        ]);
    }
}
