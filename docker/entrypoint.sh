#!/bin/sh
set -e
cd /var/www/html

if [ "${DOCKER_ROLE:-app}" = "app" ]; then
    if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
        echo "Waiting for PostgreSQL..."
        tries=60
        while [ "$tries" -gt 0 ]; do
            if pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-spennies}" -d "${DB_DATABASE:-spennies}" >/dev/null 2>&1; then
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
