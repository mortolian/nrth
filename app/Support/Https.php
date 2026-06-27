<?php

namespace App\Support;

use Illuminate\Http\Request;

class Https
{
    public static function shouldForce(): bool
    {
        if (filter_var(config('https.allow_http'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return filter_var(config('https.force'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function shouldSendHsts(): bool
    {
        return static::shouldForce()
            && filter_var(config('https.hsts.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Docker health checks hit http://127.0.0.1:8000/up inside the container.
     * Those loopback probes must not be redirected to HTTPS.
     */
    public static function isInternalHealthCheck(Request $request): bool
    {
        if (! $request->is('up')) {
            return false;
        }

        return in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    public static function hstsHeaderValue(): string
    {
        $value = 'max-age='.config('https.hsts.max_age');

        if (filter_var(config('https.hsts.include_subdomains'), FILTER_VALIDATE_BOOLEAN)) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }
}
