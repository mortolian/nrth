<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamRole;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\PermissionCatalog;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamRoleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_unless($team !== null && $user->belongsToTeam($team), 403);
        $this->authorizeTeam('settings.team', $request, $team);
        EnsureTeamSystemRoles::ensureFor($team);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::keys())],
        ]);

        $key = $this->uniqueKey($team, Str::slug($validated['name']) ?: 'role');

        TeamRole::query()->create([
            'team_id' => $team->id,
            'key' => $key,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'permissions' => PermissionCatalog::sanitize($validated['permissions']),
            'is_system' => false,
        ]);

        return back()->with('success', __('Role created.'));
    }

    public function update(Request $request, TeamRole $teamRole): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_unless($team !== null && $teamRole->team_id === $team->id, 403);
        $this->authorizeTeam('settings.team', $request, $team);

        if ($teamRole->is_system) {
            throw ValidationException::withMessages([
                'name' => __('Built-in roles cannot be edited. Create a custom role instead.'),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::keys())],
        ]);

        $teamRole->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'permissions' => PermissionCatalog::sanitize($validated['permissions']),
        ]);

        return back()->with('success', __('Role updated.'));
    }

    public function destroy(Request $request, TeamRole $teamRole): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        abort_unless($team !== null && $teamRole->team_id === $team->id, 403);
        $this->authorizeTeam('settings.team', $request, $team);

        if ($teamRole->is_system) {
            throw ValidationException::withMessages([
                'role' => __('Built-in roles cannot be deleted.'),
            ]);
        }

        $memberCount = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('role', $teamRole->key)
            ->count();

        $inviteCount = $team->teamInvitations()
            ->where('role', $teamRole->key)
            ->count();

        if ($memberCount > 0 || $inviteCount > 0) {
            throw ValidationException::withMessages([
                'role' => __('Reassign members and invitations before deleting this role.'),
            ]);
        }

        $teamRole->delete();

        return back()->with('success', __('Role deleted.'));
    }

    private function uniqueKey(Team $team, string $base): string
    {
        $base = Str::limit($base, 40, '');
        if ($base === '' || in_array($base, [RolePresets::ACCOUNTANT, RolePresets::VIEWER, 'owner'], true)) {
            $base = 'role';
        }

        $key = $base;
        $i = 2;
        while (
            TeamRole::query()->where('team_id', $team->id)->where('key', $key)->exists()
        ) {
            $key = $base.'-'.$i;
            $i++;
        }

        return $key;
    }
}
