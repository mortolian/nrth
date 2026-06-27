#!/usr/bin/env bash
#
# Docker Compose wrapper — uses sudo when the current user cannot access docker.sock
# (common right after install adds you to the docker group but before re-login).
#
# Usage:
#   ./scripts/compose.sh up -d
#   ./scripts/compose.sh exec -it app php artisan app:install
#
# Override: COMPOSE="docker compose" or COMPOSE="sudo docker compose"

set -euo pipefail

if [[ -n "${COMPOSE:-}" && "${COMPOSE}" != *compose.sh* ]]; then
    # shellcheck disable=SC2086
    exec $COMPOSE "$@"
fi

if docker info >/dev/null 2>&1; then
    exec docker compose "$@"
fi

if command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
    exec sudo docker compose "$@"
fi

echo "error: permission denied accessing Docker at unix:///var/run/docker.sock" >&2
echo "" >&2
echo "If install just added you to the docker group, either:" >&2
echo "  newgrp docker                         # apply group in this shell" >&2
echo "  sudo docker compose ...               # one-off with sudo" >&2
echo "  log out and back in                   # permanent fix" >&2
exit 1
