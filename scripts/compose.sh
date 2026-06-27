#!/usr/bin/env bash
#
# Docker Compose wrapper — uses sudo when the current user cannot access docker.sock
# (common right after install adds you to the docker group but before re-login).
#
# Usage:
#   ./scripts/compose.sh up -d
#   ./scripts/compose.sh exec -it app php artisan app:install
#
# Destructive commands require explicit confirmation:
#   ./scripts/compose.sh down -v --force
#   NRTH_FORCE=1 ./scripts/compose.sh down -v
#
# Override: COMPOSE="docker compose" or COMPOSE="sudo docker compose"

set -euo pipefail

compose_down_removes_volumes() {
    local arg
    for arg in "$@"; do
        case "$arg" in
            -v|--volumes) return 0 ;;
        esac
    done
    return 1
}

compose_args_include_force() {
    local arg
    for arg in "$@"; do
        [[ "$arg" == "--force" ]] && return 0
    done
    [[ "${NRTH_FORCE:-}" == "1" ]]
}

# Our --force is consumed by this wrapper; docker compose does not accept it.
compose_args_without_force() {
    local arg
    for arg in "$@"; do
        [[ "$arg" == "--force" ]] && continue
        printf '%s\n' "$arg"
    done
}

run_compose() {
    local -a filtered=()
    local arg
    while IFS= read -r arg; do
        filtered+=("$arg")
    done < <(compose_args_without_force "$@")

    if docker info >/dev/null 2>&1; then
        docker compose "${filtered[@]}"
        return
    fi

    if command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
        sudo docker compose "${filtered[@]}"
        return
    fi

    echo "error: permission denied accessing Docker at unix:///var/run/docker.sock" >&2
    echo "" >&2
    echo "If install just added you to the docker group, either:" >&2
    echo "  newgrp docker                         # apply group in this shell" >&2
    echo "  sudo docker compose ...               # one-off with sudo" >&2
    echo "  log out and back in                   # permanent fix" >&2
    exit 1
}

guard_destructive_compose() {
    [[ "${1:-}" == "down" ]] || return 0
    compose_down_removes_volumes "${@:2}" || return 0
    compose_args_include_force "${@:2}" && return 0

    cat >&2 <<'EOF'
error: `compose down -v` permanently deletes Docker volumes (PostgreSQL, Redis, MinIO,
       storage uploads, vendor, and node_modules). All application data in those
       volumes will be lost.

To proceed anyway, pass --force:
  ./scripts/compose.sh down -v --force

Or set NRTH_FORCE=1 for non-interactive scripts.

Safe shutdown (containers only, data preserved):
  ./scripts/compose.sh down
EOF
    exit 1
}

if [[ -n "${COMPOSE:-}" && "${COMPOSE}" != *compose.sh* ]]; then
    guard_destructive_compose "$@"
    # shellcheck disable=SC2086
    exec $COMPOSE "$@"
fi

guard_destructive_compose "$@"

run_compose "$@"
