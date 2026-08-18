<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIncompleteOnboarding
{
    /**
     * Send verified users who have not finished onboarding to the setup wizard.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($request->routeIs([
            'onboarding.*',
            'verification.*',
            'logout',
            'team-invitations.accept',
            'team-invitations.join',
            'login',
            'register',
            'password.*',
        ])) {
            return $next($request);
        }

        $user = $user->fresh();

        if ($user === null || $user->completed_onboarding_at !== null) {
            return $next($request);
        }

        // Only owners of the *current* business need the setup wizard.
        $team = $user->currentTeam;
        if ($team === null || ! $user->ownsTeam($team)) {
            return $next($request);
        }

        return redirect()->route('onboarding.setup');
    }
}
