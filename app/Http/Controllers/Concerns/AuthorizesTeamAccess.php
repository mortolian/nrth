<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Team;
use App\Support\TeamAccess\TeamAccess;
use Illuminate\Http\Request;

trait AuthorizesTeamAccess
{
    protected function authorizeTeam(string $permission, ?Request $request = null, ?Team $team = null): void
    {
        $request ??= request();
        $user = $request->user();
        $team ??= $user?->currentTeam;

        abort_unless($user !== null && TeamAccess::allows($user, $team, $permission), 403);
    }
}
