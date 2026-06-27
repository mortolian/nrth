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
        if (filter_var(config('https.allow_http'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

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

    /**
     * Build the HTTPS redirect target from APP_URL so plain HTTP on Octane (:8000)
     * redirects to the public URL (e.g. https://192.168.1.204/) instead of
     * https://host:8000 which has no TLS listener.
     */
    public static function secureRedirectUrl(string $requestUri): string
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root === '') {
            return 'https://localhost'.$requestUri;
        }

        $parsed = parse_url($root);
        $host = $parsed['host'] ?? 'localhost';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return 'https://'.$host.$port.$requestUri;
    }
}
