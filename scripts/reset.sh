#!/usr/bin/env bash
#
# Nuclear reset: stop the stack, delete all Docker volumes, and re-run install.
# All database rows, uploads, Redis data, and MinIO objects are destroyed.
#
# Usage:
#   ./scripts/reset.sh --force
#   ./scripts/reset.sh --force --accept-data-risk --lan --install-dir /opt/nrth
#   ./scripts/reset.sh --force --accept-data-risk --production --non-interactive
#
# Requires --force (or NRTH_FORCE=1). Backs up .env before wiping volumes.
# Remaining arguments are passed to scripts/install.sh after the reset.
#
# Options:
#   --force              Required — confirms you intend to delete all data volumes
#   --accept-data-risk   Acknowledge backup responsibility (required for non-interactive)
#   --install-dir PATH   Install location (default: repo root or /opt/nrth)
#   --keep-env             Do not back up or regenerate .env (advanced; usually wrong)
#   -h, --help           Show help

set -euo pipefail

DEFAULT_INSTALL_DIR="/opt/nrth"
INSTALL_DIR=""
FORCE=0
KEEP_ENV=0
ACCEPT_DATA_RISK=0
INSTALL_ARGS=()

usage() {
    sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'
}

log() {
    echo "==> $*"
}

die() {
    echo "error: $*" >&2
    exit 1
}

confirm_data_risk() {
    if [[ "$ACCEPT_DATA_RISK" -eq 1 ]]; then
        return 0
    fi

    local non_interactive=0
    for arg in "${INSTALL_ARGS[@]}"; do
        [[ "$arg" == "--non-interactive" ]] && non_interactive=1
    done
    if ! [[ -t 0 && -t 1 ]]; then
        non_interactive=1
    fi

    if [[ "$non_interactive" -eq 1 ]]; then
        die "non-interactive reset requires --accept-data-risk (back up this machine first; see --help)"
    fi

    echo ""
    echo "This will permanently delete all Docker volumes (database, uploads, Redis, MinIO)."
    echo ""
    echo "Before continuing, please confirm:"
    echo "  • You have verified this machine is backed up."
    echo "  • nrth and its maintainers are not responsible for any data loss."
    echo ""
    read -r -p "Continue? [y/N]: " confirm
    case "$confirm" in
        [yY]|[yY][eE][sS])
            ACCEPT_DATA_RISK=1
            ;;
        *)
            die "reset cancelled"
            ;;
    esac
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --force)
                FORCE=1
                shift
                ;;
            --accept-data-risk)
                ACCEPT_DATA_RISK=1
                INSTALL_ARGS+=("$1")
                shift
                ;;
            --install-dir)
                INSTALL_DIR="${2:?--install-dir requires a value}"
                shift 2
                ;;
            --keep-env)
                KEEP_ENV=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                INSTALL_ARGS+=("$1")
                shift
                ;;
        esac
    done

    if [[ "$FORCE" -ne 1 && "${NRTH_FORCE:-}" != "1" ]]; then
        cat >&2 <<'EOF'
error: reset.sh permanently deletes all Docker volumes (PostgreSQL, Redis, MinIO,
       storage uploads, vendor, and node_modules).

Re-run with --force when you have no data to keep:
  ./scripts/reset.sh --force

For a non-destructive fix, use:
  ./scripts/repair.sh
EOF
        exit 1
    fi
}

script_path() {
    local source="${BASH_SOURCE[0]}"
    while [[ -L "$source" ]]; do
        local dir
        dir="$(cd -P "$(dirname "$source")" && pwd)"
        source="$(readlink "$source")"
        [[ "$source" != /* ]] && source="$dir/$source"
    done
    cd -P "$(dirname "$source")" && pwd
}

detect_install_dir() {
    if [[ -n "$INSTALL_DIR" ]]; then
        cd "$INSTALL_DIR" && pwd
        return 0
    fi
    local dir
    dir="$(script_path)"
    if [[ -f "$dir/../compose.yaml" ]]; then
        cd "$dir/.." && pwd
        return 0
    fi
    if [[ -d "$DEFAULT_INSTALL_DIR" && -f "$DEFAULT_INSTALL_DIR/compose.yaml" ]]; then
        cd "$DEFAULT_INSTALL_DIR" && pwd
        return 0
    fi
    die "could not find install dir — pass --install-dir /opt/nrth"
}

main() {
    parse_args "$@"

    ROOT_DIR="$(detect_install_dir)"
    cd "$ROOT_DIR"
    [[ -f compose.yaml ]] || die "compose.yaml not found in ${ROOT_DIR}"

    confirm_data_risk

    COMPOSE="${COMPOSE:-$ROOT_DIR/scripts/compose.sh}"

    if [[ -f .env ]]; then
        local backup=".env.backup.$(date +%Y%m%d%H%M%S)"
        if [[ "$KEEP_ENV" -eq 1 ]]; then
            log "Keeping existing .env (--keep-env)"
        else
            log "Moving .env to ${backup} (install will create a fresh .env)"
            mv .env "$backup"
        fi
    fi

    log "Stopping stack and removing all Docker volumes (destructive)"
    NRTH_FORCE=1 $COMPOSE down -v --force 2>/dev/null || NRTH_FORCE=1 $COMPOSE down -v

    log "Removing local deploy state (safe to delete)"
    rm -f storage/framework/.deploy-composer-hash storage/framework/.deploy-npm-hash 2>/dev/null || true
    rm -f storage/app/.docker_bootstrapped 2>/dev/null || true

    log "Re-running install (fresh volumes)"
    local -a install_cmd=("$ROOT_DIR/scripts/install.sh" "--install-dir" "$ROOT_DIR")
    if [[ "$ACCEPT_DATA_RISK" -eq 1 ]]; then
        install_cmd+=("--accept-data-risk")
    fi
    if [[ ${#INSTALL_ARGS[@]} -gt 0 ]]; then
        install_cmd+=("${INSTALL_ARGS[@]}")
    fi

    exec "${install_cmd[@]}"
}

main "$@"
