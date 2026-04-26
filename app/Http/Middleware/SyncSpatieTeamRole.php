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

                if ($user->ownsTeam($team)) {
                    $user->assignRole('team_owner');
                } else {
                    $member = $team->users->firstWhere('id', $user->id);
                    $key = $member?->membership->role ?? 'viewer';
                    $spatie = $key === 'accountant' ? 'team_accountant' : 'team_viewer';
                    $user->assignRole($spatie);
                }
            }
        }

        return $next($request);
    }
}
