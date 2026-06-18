#!/usr/bin/env bash
#
# First-time self-hosted install using Docker Compose.
# Run from the project root after cloning and editing .env.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE="${COMPOSE:-docker compose}"

if [[ ! -f .env ]]; then
    if [[ -f .env.example ]]; then
        cp .env.example .env
        echo "Created .env from .env.example — edit it before continuing if you have not already."
    else
        echo "Missing .env. Copy .env.example to .env and set production values."
        exit 1
    fi
fi

if ! grep -q '^APP_KEY=.\+' .env 2>/dev/null; then
    echo "Generate APP_KEY in .env before production use:"
    echo "  docker compose run --rm app php artisan key:generate"
fi

echo "==> Building and starting containers (this may take a few minutes the first time)"
$COMPOSE up -d --build

echo "==> Waiting for the app container to become healthy..."
TRIES=60
until $COMPOSE exec -T app curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1; do
    TRIES=$((TRIES - 1))
    if [[ "$TRIES" -le 0 ]]; then
        echo "App did not become ready in time. Check: docker compose logs app"
        exit 1
    fi
    sleep 3
done

echo "==> Running interactive installer (admin user + default chart of accounts)"
$COMPOSE exec app php artisan app:install

APP_PORT="$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || echo 8000)"
echo ""
echo "Installation complete."
echo "  Open: http://localhost:${APP_PORT}"
echo "  For HTTPS and a public domain, see docs/SELF_HOST.md"
