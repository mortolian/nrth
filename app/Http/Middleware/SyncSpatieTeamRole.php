<?php

namespace App\Http\Middleware;

use App\Support\EnsureTeamSpatieRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncSpatieTeamRole
{
    /** @var list<string> */
    private const TEAM_ROLE_NAMES = ['team_owner', 'team_accountant', 'team_viewer'];

    /**
     * Keep Spatie roles aligned with the current Jetstream team membership (per-request).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        EnsureTeamSpatieRoles::sync();

        $user = $request->user();
        if ($user !== null && $user->current_team_id) {
            $team = $user->currentTeam;
            if ($team !== null && $user->belongsToTeam($team)) {
                foreach (self::TEAM_ROLE_NAMES as $roleName) {
                    if ($user->hasRole($roleName)) {
                        $user->removeRole($roleName);
                    }
                }

                $member = $team->users->firstWhere('id', $user->id);
                $roleKey = $user->ownsTeam($team)
                    ? 'owner'
                    : ($member?->membership->role ?? 'viewer');

                if ($roleKey === 'owner') {
                    $user->assignRole('team_owner');
                } elseif ($roleKey === 'accountant') {
                    $user->assignRole('team_accountant');
                } else {
                    $user->assignRole('team_viewer');
                }
            }
        }

        return $next($request);
    }
}
