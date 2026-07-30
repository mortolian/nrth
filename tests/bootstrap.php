<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHPUnit bootstrap
|--------------------------------------------------------------------------
|
| PHPUnit's <env force="true"> updates putenv() and $_ENV, but not $_SERVER.
| Docker Compose injects DB_*, SESSION_*, etc. into $_SERVER. Laravel's env()
| reads $_SERVER preferentially, so without this sync RefreshDatabase would
| target the app database and POSTs can 419 under the wrong session driver.
|
*/

require dirname(__DIR__).'/vendor/autoload.php';

foreach ($_ENV as $key => $value) {
    if (! is_string($key) || is_array($value)) {
        continue;
    }

    if (! array_key_exists($key, $_SERVER) || $_SERVER[$key] !== $value) {
        $_SERVER[$key] = $value;
    }
}
