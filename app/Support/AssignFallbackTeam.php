<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;

final class AssignFallbackTeam
{
    /**
     * After leaving (or being removed from) a business, switch to another
     * membership or owned team when current_team_id was cleared.
     */
    public static function for(User $user): ?Team
    {
        $user = $user->fresh();
        if ($user === null) {
            return null;
        }

        $current = $user->currentTeam;
        if ($current !== null && $user->belongsToTeam($current)) {
            return $current;
        }

        $fallback = $user->ownedTeams()->orderBy('id')->first()
            ?? $user->teams()->orderBy('teams.id')->first();

        if ($fallback === null) {
            return null;
        }

        $user->switchTeam($fallback);

        return $fallback;
    }
}
