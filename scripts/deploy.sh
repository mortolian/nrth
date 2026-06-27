#!/usr/bin/env bash
#
# Pull latest code and apply updates inside the Docker Compose stack.
#
# Data safety: this script does NOT remove Docker volumes, run migrate:fresh, or
# rotate database/MinIO passwords. Migrations are applied incrementally. The only
# destructive git step is `git reset --hard`, which affects tracked files in this
# clone only (not Postgres, Redis, MinIO, or storage volumes).
#
# Usage:
#   ./scripts/deploy.sh          # dev/staging (fast: migrate + restart queues)
#   ./scripts/deploy.sh production   # production (runs php artisan app:update)
#
# Environment:
#   SKIP_GIT=1     Skip git pull (e.g. when a CI job already updated the tree)
#   GIT_BRANCH=master   Branch to pull (default: master)
#   COMPOSE="docker compose"  Override compose command (default: scripts/compose.sh)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

MODE="${1:-dev}"
GIT_BRANCH="${GIT_BRANCH:-master}"
COMPOSE="${COMPOSE:-$ROOT_DIR/scripts/compose.sh}"

hash_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

if [[ ! -f compose.yaml ]]; then
    echo "compose.yaml not found. Run this script from the project root."
    exit 1
fi

if [[ "${SKIP_GIT:-0}" != "1" ]] && [[ -d .git ]] && [[ "$(id -u)" -ne 0 ]]; then
    git_owner="$(stat -c '%U' .git 2>/dev/null || stat -f '%Su' .git)"
    current_user="$(id -un)"
    if [[ "$git_owner" == "root" && "$git_owner" != "$current_user" ]]; then
        echo "error: ${ROOT_DIR} is owned by root (install was run with sudo)." >&2
        echo "Fix: sudo chown -R ${current_user}:${current_user} ${ROOT_DIR}" >&2
        exit 1
    fi
fi

if [[ "${SKIP_GIT:-0}" != "1" ]]; then
    if [[ -d .git ]] && { ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; }; then
        echo "warning: uncommitted changes in ${ROOT_DIR} will be discarded by git reset --hard"
        echo "         (Docker volumes and database data are not affected)"
    fi
    echo "==> Pulling latest from origin/${GIT_BRANCH} (git reset --hard — code tree only)"
    git fetch origin "${GIT_BRANCH}"
    git reset --hard "origin/${GIT_BRANCH}"
fi

if ! $COMPOSE ps --status running app 2>/dev/null | grep -q app; then
    echo "==> App container is not running. Starting stack..."
    $COMPOSE up -d --build
fi

run_app() {
    $COMPOSE exec -T app "$@"
}

STATE_DIR="$ROOT_DIR/storage/framework"
mkdir -p "$STATE_DIR"
COMPOSER_HASH_FILE="$STATE_DIR/.deploy-composer-hash"
NPM_HASH_FILE="$STATE_DIR/.deploy-npm-hash"

CURRENT_COMPOSER="$(hash_file composer.lock)"
if [[ ! -f "$COMPOSER_HASH_FILE" ]] || [[ "$(cat "$COMPOSER_HASH_FILE")" != "$CURRENT_COMPOSER" ]]; then
    echo "==> composer.lock changed — installing PHP dependencies"
    run_app composer install --no-interaction --prefer-dist
    echo "$CURRENT_COMPOSER" > "$COMPOSER_HASH_FILE"
else
    echo "==> composer.lock unchanged — skipping composer install"
fi

CURRENT_NPM="$(hash_file package-lock.json)"
if [[ ! -f "$NPM_HASH_FILE" ]] || [[ "$(cat "$NPM_HASH_FILE")" != "$CURRENT_NPM" ]]; then
    echo "==> package-lock.json changed — installing npm deps and building assets"
    run_app npm ci --no-audit --no-fund
    run_app npm run build
    echo "$CURRENT_NPM" > "$NPM_HASH_FILE"
else
    echo "==> package-lock.json unchanged — skipping npm build"
fi

if [[ "$MODE" == "production" ]]; then
    echo "==> Running production update (maintenance mode, migrate, caches, workers)"
    run_app php artisan app:update
else
    echo "==> Running development update (migrate + queue restart)"
    run_app php artisan migrate --force --no-interaction
    run_app php artisan queue:restart
    run_app php artisan horizon:terminate 2>/dev/null || true
    $COMPOSE restart worker 2>/dev/null || true
fi

echo ""
echo "Deploy finished ($(date -u +"%Y-%m-%d %H:%M:%S UTC"))."
echo "  Mode: ${MODE}"
echo "  Commit: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
echo "  Data: volumes and database preserved; pending migrations applied only"
