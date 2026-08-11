<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamModule
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $team = $request->user()?->currentTeam;

        abort_unless($team !== null && $team->moduleEnabled($module), 403, __('This feature is not enabled for this business.'));

        return $next($request);
    }
}
