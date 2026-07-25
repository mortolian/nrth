<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamRole;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\PermissionCatalog;
use App\Support\TeamAccess\RolePresets;
use App\Support\TeamAccess\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_unless($team !== null && $user->belongsToTeam($team), 403);

        return $this->show($request, $team);
    }

    public function updateSessionIdleTimeout(Request $request): RedirectResponse
    {
        $this->authorizeTeam('settings.team', $request);
        $user = $request->user();
        $team = $user->currentTeam;

        abort_unless($team !== null && $user->belongsToTeam($team), 403);
        abort_unless($user->can('update', $team), 403);

        $max = (int) config('session.lifetime');
        $validated = $request->validate([
            'session_idle_timeout_minutes' => ['required', 'integer', 'min:0', 'max:'.$max],
        ]);

        $settings = $team->mergedBusinessSettings();
        $settings['session_idle_timeout_minutes'] = (int) $validated['session_idle_timeout_minutes'];
        $team->business_settings = $settings;
        $team->save();

        return back()->with('success', __('Session idle timeout saved.'));
    }

    public function show(Request $request, Team $team): Response
    {
        $user = $request->user();

        abort_unless($user->belongsToTeam($team), 403);

        Gate::authorize('view', $team);

        EnsureTeamSystemRoles::ensureFor($team);

        $team->loadMissing(['owner', 'users', 'teamInvitations', 'teamRoles']);

        $rolesByKey = $team->teamRoles->keyBy('key');

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
            $roleKey = $memberUser->membership->role ?? RolePresets::VIEWER;
            $teamRole = $rolesByKey->get($roleKey);
            $members->push([
                'id' => $memberUser->id,
                'name' => $memberUser->name,
                'email' => $memberUser->email,
                'profile_photo_url' => $memberUser->profile_photo_url,
                'role_key' => $roleKey,
                'role_label' => $teamRole?->name ?? ucfirst($roleKey),
                'is_owner' => false,
            ]);
        }

        $invitations = $team->teamInvitations->map(function ($invitation) use ($rolesByKey) {
            $roleKey = (string) $invitation->role;
            $teamRole = $rolesByKey->get($roleKey);

            return [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_key' => $roleKey,
                'role_label' => $teamRole?->name ?? $roleKey,
            ];
        })->values()->all();

        $settings = $team->mergedBusinessSettings();

        $availableRoles = $team->teamRoles->map(function (TeamRole $role) {
            $permissions = TeamAccess::effectivePermissions($role);
            $preset = RolePresets::systemRoles()[$role->key] ?? null;

            return [
                'id' => $role->id,
                'key' => $role->key,
                'name' => $preset['name'] ?? $role->name,
                'description' => $preset['description'] ?? $role->description,
                'is_system' => (bool) $role->is_system,
                'permissions' => $permissions,
                'permission_count' => count($permissions),
            ];
        })->values()->all();

        return Inertia::render('Settings/Team', [
            'team_settings_entry' => $request->routeIs('teams.show') ? 'direct' : 'settings',
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'personal_team' => (bool) $team->personal_team,
                'owner' => $team->owner === null ? null : [
                    'name' => $team->owner->name,
                    'email' => $team->owner->email,
                    'profile_photo_url' => $team->owner->profile_photo_url,
                ],
            ],
            'members' => $members->values()->all(),
            'invitations' => $invitations,
            'available_roles' => $availableRoles,
            'permission_groups' => PermissionCatalog::groupsForUi(),
            'permissions' => [
                'canAddTeamMembers' => Gate::check('addTeamMember', $team),
                'canDeleteTeam' => Gate::check('delete', $team),
                'canRemoveTeamMembers' => Gate::check('removeTeamMember', $team),
                'canUpdateTeam' => Gate::check('update', $team),
                'canUpdateTeamMembers' => Gate::check('updateTeamMember', $team),
                'canManageRoles' => Gate::check('update', $team),
            ],
            'session_idle_timeout_minutes' => (int) ($settings['session_idle_timeout_minutes'] ?? 0),
            'session_lifetime_minutes' => (int) config('session.lifetime'),
            'role_summaries' => [
                [
                    'key' => 'owner',
                    'title' => 'Owner',
                    'description' => 'Full access to all features and team management. Cannot be assigned as a member role.',
                    'is_system' => true,
                    'permission_count' => count(RolePresets::ownerPermissions()),
                ],
                ...array_map(function (array $role) {
                    return [
                        'key' => $role['key'],
                        'title' => $role['name'],
                        'description' => $role['description'],
                        'is_system' => true,
                        'permission_count' => count($role['permissions']),
                    ];
                }, array_values(array_filter(
                    $availableRoles,
                    fn (array $role): bool => $role['is_system']
                ))),
                ...array_map(function (array $role) {
                    return [
                        'key' => $role['key'],
                        'title' => $role['name'],
                        'description' => $role['description'] ?: 'Custom role with '.$role['permission_count'].' permissions.',
                        'is_system' => false,
                        'permission_count' => $role['permission_count'],
                        'id' => $role['id'],
                    ];
                }, array_values(array_filter(
                    $availableRoles,
                    fn (array $role): bool => ! $role['is_system']
                ))),
            ],
        ]);
    }
}
