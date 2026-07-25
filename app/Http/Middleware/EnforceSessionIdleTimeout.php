<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionIdleTimeout
{
    public const SESSION_KEY = 'idle_timeout.last_activity_at';

    /**
     * Log the user out when the current team's idle timeout has been exceeded.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $team = $user->currentTeam;
        if ($team === null) {
            return $next($request);
        }

        $minutes = (int) ($team->mergedBusinessSettings()['session_idle_timeout_minutes'] ?? 0);
        if ($minutes <= 0) {
            return $next($request);
        }

        $now = now()->getTimestamp();
        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if (is_numeric($lastActivity) && ($now - (int) $lastActivity) > ($minutes * 60)) {
            Auth::guard('web')->logout();
            Auth::forgetGuards();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('status', 'You were signed out due to inactivity.');
        }

        $request->session()->put(self::SESSION_KEY, $now);

        return $next($request);
    }
}
