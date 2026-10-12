<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

final class TeamMailRecipients
{
    /**
     * Team owner and members who may see the record and have not opted out.
     *
     * @return Collection<int, User>
     */
    public static function for(Team $team, string $permission, string $preferenceKey): Collection
    {
        $team->loadMissing(['owner', 'users']);

        return collect([$team->owner])
            ->merge($team->users)
            ->filter(fn ($user): bool => $user instanceof User && trim((string) $user->email) !== '')
            ->unique('id')
            ->filter(function (User $user) use ($team, $permission, $preferenceKey): bool {
                if (! $user->canOnTeam($permission, $team)) {
                    return false;
                }

                return (bool) ($user->mergedPreferences()[$preferenceKey] ?? true);
            })
            ->values();
    }
}
