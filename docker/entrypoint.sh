#!/bin/sh
set -e
cd /var/www/html

# Host bind mount can leave bootstrap/cache/packages.php from `composer install` (with dev
# deps) while /vendor matches the image or a volume from `composer install --no-dev`, so the
# manifest references packages that are not installed (e.g. laravel/pail).
if [ -f bootstrap/cache/packages.php ]; then
	if ! php -r '
		$packages = require "bootstrap/cache/packages.php";
		foreach (array_keys($packages) as $name) {
			$path = "vendor/" . str_replace("/", DIRECTORY_SEPARATOR, $name) . "/composer.json";
			if (! is_file($path)) {
				exit(1);
			}
		}
		exit(0);
	'; then
		echo "Removing stale bootstrap/cache/packages.php (out of sync with vendor)."
		rm -f bootstrap/cache/packages.php
	fi
fi

# Bind mount + named volumes: vendor/node_modules may be empty on first run.
if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies (first run / empty vendor volume)..."
    composer install --no-interaction --prefer-dist
fi

# Bind-mounted app/ can add classes after vendor was built; refresh autoload so PSR-4 resolves them
# (images must not use --classmap-authoritative for the same reason).
if [ "${LARAVEL_SAIL:-}" = "1" ] && [ "${DOCKER_ROLE:-app}" = "app" ]; then
    composer dump-autoload --optimize --no-scripts 2>/dev/null || true
fi

if [ "${DOCKER_ROLE:-app}" = "app" ] && { [ ! -d node_modules/vite ] || [ ! -f node_modules/chokidar/package.json ]; }; then
    echo "Installing npm dependencies (first run / empty node_modules volume / missing Octane watcher deps)..."
    npm ci --no-audit --no-fund
fi

# Octane runs `node file-watcher.cjs` with cwd `vendor/laravel/octane/bin`. Node resolves
# `require('chokidar')` starting at `vendor/laravel/octane/bin/node_modules` — the *first* search
# path — before walking up through vendor. A symlink to the app tree avoids duplicate installs and
# fixes cases where walking past a volume mount failed to reach /var/www/html/node_modules.
if [ "${DOCKER_ROLE:-app}" = "app" ] && [ -f vendor/laravel/octane/bin/file-watcher.cjs ]; then
	OCT_BIN="/var/www/html/vendor/laravel/octane/bin"
	APP_NM="/var/www/html/node_modules"
	rm -rf "$OCT_BIN/node_modules"
	if [ -f "$APP_NM/chokidar/package.json" ]; then
		ln -s "$APP_NM" "$OCT_BIN/node_modules"
	else
		echo "Installing chokidar for Octane --watch (under vendor/laravel/octane/bin)..."
		npm install --no-save --no-package-lock --prefix "$OCT_BIN" chokidar@4.0.3
	fi
fi

if [ "${DOCKER_ROLE:-app}" = "app" ]; then
    if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
        echo "Waiting for PostgreSQL..."
        tries=60
        while [ "$tries" -gt 0 ]; do
            if pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-nrth}" -d "${DB_DATABASE:-nrth}" >/dev/null 2>&1; then
                break
            fi
            tries=$((tries - 1))
            sleep 2
        done
        if [ "$tries" -eq 0 ]; then
            echo "PostgreSQL did not become ready in time." >&2
            exit 1
        fi
    fi

    if [ ! -f storage/app/.docker_bootstrapped ]; then
        php artisan migrate --force --no-interaction
        php artisan storage:link --force --no-interaction || true
        : > storage/app/.docker_bootstrapped
    fi
fi

exec "$@"
