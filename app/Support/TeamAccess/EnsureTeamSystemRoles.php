<?php

namespace App\Support\TeamAccess;

use App\Models\Team;
use App\Models\TeamRole;

final class EnsureTeamSystemRoles
{
    public static function ensureFor(Team $team): void
    {
        foreach (RolePresets::systemRoles() as $role) {
            TeamRole::query()->updateOrCreate(
                [
                    'team_id' => $team->id,
                    'key' => $role['key'],
                ],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'permissions' => $role['permissions'],
                    'is_system' => true,
                ]
            );
        }
    }

    public static function ensureForAllTeams(): void
    {
        Team::query()->orderBy('id')->each(function (Team $team): void {
            self::ensureFor($team);
        });
    }
}
