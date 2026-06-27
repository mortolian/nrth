<?php

namespace App\Http\Middleware;

use App\Support\Https;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    /**
     * Redirect plain HTTP to HTTPS, set HSTS on secure responses, and allow
     * loopback health checks over HTTP inside the container.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Https::shouldForce()) {
            return $next($request);
        }

        if (! $request->secure() && ! Https::isInternalHealthCheck($request)) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        if ($request->secure() && Https::shouldSendHsts()) {
            $response->headers->set('Strict-Transport-Security', Https::hstsHeaderValue());
        }

        return $response;
    }
}
