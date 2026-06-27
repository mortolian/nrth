<?php

$allowHttp = filter_var(env('APP_ALLOW_HTTP', false), FILTER_VALIDATE_BOOLEAN);

return [

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | When enabled, HTTP requests are redirected to HTTPS and generated URLs
    | use the https scheme. Disabled automatically when APP_ALLOW_HTTP is true.
    |
    */

    'force' => filter_var(
        env('APP_FORCE_HTTPS', ! $allowHttp),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Allow Plain HTTP
    |--------------------------------------------------------------------------
    |
    | Explicit opt-in for local development only. Never enable on a server
    | reachable by others — this is a financial application.
    |
    */

    'allow_http' => $allowHttp,

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Sent on HTTPS responses when force HTTPS is active. Browsers will refuse
    | plain HTTP for the max-age duration after the first secure visit.
    |
    */

    'hsts' => [
        'enabled' => filter_var(env('APP_HSTS', true), FILTER_VALIDATE_BOOLEAN),
        'max_age' => (int) env('APP_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => filter_var(env('APP_HSTS_SUBDOMAINS', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
