<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHPUnit bootstrap
|--------------------------------------------------------------------------
|
| PHPUnit's <env force="true"> updates putenv() and $_ENV, but not $_SERVER.
| Docker Compose injects DB_* into $_SERVER. Laravel's env() prefers $_SERVER,
| so RefreshDatabase can target the app Postgres unless we hard-force sqlite.
|
| Never rely on syncing $_ENV alone — always overwrite the critical DB keys.
|
*/

require dirname(__DIR__).'/vendor/autoload.php';

$forced = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
];

foreach ($forced as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

foreach ($_ENV as $key => $value) {
    if (! is_string($key) || is_array($value)) {
        continue;
    }

    if (! array_key_exists($key, $_SERVER) || $_SERVER[$key] !== $value) {
        $_SERVER[$key] = $value;
    }
}
