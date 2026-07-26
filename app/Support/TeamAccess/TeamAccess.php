<?php

namespace App\Support\TeamAccess;

use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;

final class TeamAccess
{
    /**
     * @return list<string>
     */
    public static function permissionsFor(User $user, ?Team $team): array
    {
        if ($team === null || ! $user->belongsToTeam($team)) {
            return [];
        }

        if ($user->ownsTeam($team)) {
            return RolePresets::ownerPermissions();
        }

        $roleKey = self::membershipRoleKey($user, $team);
        $teamRole = TeamRole::query()
            ->where('team_id', $team->id)
            ->where('key', $roleKey)
            ->first();

        if ($teamRole !== null) {
            return self::effectivePermissions($teamRole);
        }

        $preset = RolePresets::permissionsFor($roleKey);

        return $preset ?? RolePresets::viewerPermissions();
    }

    public static function allows(User $user, ?Team $team, string $permission): bool
    {
        if (! PermissionCatalog::isValid($permission)) {
            return false;
        }

        return in_array($permission, self::permissionsFor($user, $team), true);
    }

    /**
     * @return list<string>
     */
    public static function effectivePermissions(TeamRole $role): array
    {
        if ($role->is_system) {
            $preset = RolePresets::permissionsFor($role->key);
            if ($preset !== null) {
                return $preset;
            }
        }

        return PermissionCatalog::sanitize($role->permissions ?? []);
    }

    public static function membershipRoleKey(User $user, Team $team): string
    {
        if ($user->ownsTeam($team)) {
            return 'owner';
        }

        $member = $team->users->firstWhere('id', $user->id);
        if ($member === null) {
            $user->loadMissing('teams');
            $member = $user->teams->firstWhere('id', $team->id);
        }

        $role = $member?->membership?->role;

        return is_string($role) && $role !== '' ? $role : RolePresets::VIEWER;
    }

    /**
     * Display label for the user's role on a team (Owner, Accountant, custom role name, etc.).
     */
    public static function membershipRoleLabel(User $user, Team $team): string
    {
        $roleKey = self::membershipRoleKey($user, $team);

        $teamRole = TeamRole::query()
            ->where('team_id', $team->id)
            ->where('key', $roleKey)
            ->first();

        if ($teamRole !== null && is_string($teamRole->name) && $teamRole->name !== '') {
            return $teamRole->name;
        }

        if ($roleKey === 'owner') {
            return 'Owner';
        }

        return ucfirst($roleKey);
    }
}
