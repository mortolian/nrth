#!/usr/bin/env bash
#
# Repair a half-broken nrth Docker Compose installation without wiping data.
#
# Fixes common failures: wrong APP_URL / HTTPS settings, Postgres password drift,
# missing Vite manifest, docker.sock permissions, and stale deploy caches.
#
# Usage:
#   ./scripts/repair.sh                          # LAN HTTP (fastest path to working app)
#   ./scripts/repair.sh --mode https --ip 192.168.1.204
#   ./scripts/repair.sh --install-dir /opt/nrth --sync-db-password --rebuild-assets
#
# Options:
#   --install-dir PATH   Install location (default: repo root or /opt/nrth)
#   --mode http|https    Browser access mode (default: http for pragmatic LAN dev)
#   --ip ADDR            LAN IP for APP_URL (default: auto-detect)
#   --sync-db-password   Align Postgres password with .env (safe, keeps data)
#   --rebuild-assets     Force npm ci && npm run build
#   --skip-env           Do not change APP_URL / HTTPS / Caddy settings
#   --bootstrap-env      Create .env from .env.example when missing (then run repair)
#   --non-interactive    No prompts
#   -h, --help           Show help

set -euo pipefail

DEFAULT_INSTALL_DIR="/opt/nrth"
INSTALL_DIR=""
ACCESS_MODE="http"
LAN_IP=""
SYNC_DB=1
REBUILD_ASSETS=0
SKIP_ENV=0
BOOTSTRAP_ENV=0
NON_INTERACTIVE=0

usage() {
    sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'
}

log() {
    echo "==> $*"
}

die() {
    echo "error: $*" >&2
    exit 1
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --install-dir)
                INSTALL_DIR="${2:?--install-dir requires a value}"
                shift 2
                ;;
            --mode)
                ACCESS_MODE="${2:?--mode requires a value}"
                shift 2
                ;;
            --ip)
                LAN_IP="${2:?--ip requires a value}"
                shift 2
                ;;
            --sync-db-password)
                SYNC_DB=1
                shift
                ;;
            --no-sync-db-password)
                SYNC_DB=0
                shift
                ;;
            --rebuild-assets)
                REBUILD_ASSETS=1
                shift
                ;;
            --skip-env)
                SKIP_ENV=1
                shift
                ;;
            --bootstrap-env)
                BOOTSTRAP_ENV=1
                shift
                ;;
            --non-interactive)
                NON_INTERACTIVE=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                die "unknown option: $1 (try --help)"
                ;;
        esac
    done

    case "$ACCESS_MODE" in
        http|https) ;;
        *) die "--mode must be http or https (got: ${ACCESS_MODE})" ;;
    esac
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

set_env_var() {
    local key="$1"
    local value="$2"
    local file="$3"
    if grep -q "^${key}=" "$file" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        echo "${key}=${value}" >> "$file"
    fi
}

read_env_var() {
    local key="$1"
    local file="$2"
    grep -E "^${key}=" "$file" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'
}

detect_lan_ip() {
    if [[ -n "$LAN_IP" ]]; then
        printf '%s' "$LAN_IP"
        return 0
    fi
    local ip=""
    if command -v ip >/dev/null 2>&1; then
        ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") print $(i+1)}' | head -1)"
    fi
    if [[ -z "$ip" ]] && command -v hostname >/dev/null 2>&1; then
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    fi
    [[ -n "$ip" ]] || die "could not detect LAN IP — pass --ip 192.168.1.204"
    printf '%s' "$ip"
}

ensure_ownership() {
    local dir="$1"
    local target_user="${SUDO_USER:-}"
    if [[ "$(id -u)" -ne 0 ]] || [[ -z "$target_user" || "$target_user" == "root" ]]; then
        return 0
    fi
    log "Fixing ownership of ${dir} for ${target_user} (git/deploy without sudo)"
    chown -R "${target_user}:${target_user}" "$dir"
}

configure_http_access() {
    local env_file="$1"
    local ip="$2"

    log "Configuring pragmatic LAN HTTP access (APP_URL=http://${ip}:8000)"
    set_env_var APP_URL "http://${ip}:8000" "$env_file"
    set_env_var APP_ALLOW_HTTP true "$env_file"
    set_env_var APP_FORCE_HTTPS false "$env_file"
    set_env_var TRUSTED_PROXIES "*" "$env_file"
    sed -i '/^COMPOSE_PROFILES=/d' "$env_file" 2>/dev/null || true
    sed -i '/^CADDY_SITE=/d' "$env_file" 2>/dev/null || true
    sed -i '/^CADDY_TLS=/d' "$env_file" 2>/dev/null || true
}

configure_https_access() {
    local env_file="$1"
    local ip="$2"

    log "Configuring Caddy HTTPS with self-signed cert (APP_URL=https://${ip})"
    set_env_var APP_URL "https://${ip}" "$env_file"
    set_env_var APP_ALLOW_HTTP false "$env_file"
    set_env_var APP_FORCE_HTTPS true "$env_file"
    set_env_var TRUSTED_PROXIES "*" "$env_file"
    set_env_var COMPOSE_PROFILES proxy "$env_file"
    set_env_var CADDY_SITE "$ip" "$env_file"
    set_env_var CADDY_TLS internal "$env_file"
}

sync_db_password() {
    local env_file="$1"
    local db_pass db_user db_name

    db_pass="$(read_env_var DB_PASSWORD "$env_file")"
    db_user="$(read_env_var DB_USERNAME "$env_file")"
    db_name="$(read_env_var DB_DATABASE "$env_file")"
    [[ -n "$db_pass" && -n "$db_user" ]] || {
        log "Skipping DB password sync (DB_* not set in .env)"
        return 0
    }

    log "Syncing Postgres password for user ${db_user} to match .env"
    if ! $COMPOSE exec -T postgres pg_isready -U "$db_user" -d "$db_name" >/dev/null 2>&1; then
        log "Postgres not ready yet — will retry after stack is up"
        return 0
    fi

    $COMPOSE exec -T postgres psql -U "$db_user" -d postgres \
        -c "ALTER USER ${db_user} WITH PASSWORD '${db_pass}';" >/dev/null 2>&1 \
        || log "warning: could not ALTER USER (container may still be starting)"
}

wait_for_postgres() {
    local env_file="$1"
    local db_user db_name tries=30

    db_user="$(read_env_var DB_USERNAME "$env_file")"
    db_name="$(read_env_var DB_DATABASE "$env_file")"

    until $COMPOSE exec -T postgres pg_isready -U "$db_user" -d "$db_name" >/dev/null 2>&1; do
        tries=$((tries - 1))
        [[ "$tries" -gt 0 ]] || die "Postgres did not become ready"
        sleep 2
    done
}

rebuild_assets_if_needed() {
    local force="$1"

    if [[ "$force" -eq 1 ]] || ! $COMPOSE exec -T app test -f public/build/manifest.json 2>/dev/null; then
        log "Building frontend assets (Vite manifest missing or --rebuild-assets)"
        $COMPOSE exec -T app npm ci --no-audit --no-fund
        $COMPOSE exec -T app npm run build
        rm -f "$ROOT_DIR/storage/framework/.deploy-npm-hash" 2>/dev/null || true
    else
        log "Vite manifest present — skipping asset rebuild"
    fi
}

wait_for_app_health() {
    local tries=60
    until $COMPOSE exec -T app curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1; do
        tries=$((tries - 1))
        if [[ "$tries" -le 0 ]]; then
            echo "" >&2
            echo "--- docker compose ps app ---" >&2
            $COMPOSE ps app 2>&1 >&2 || true
            echo "" >&2
            echo "--- last 40 lines: docker compose logs app ---" >&2
            $COMPOSE logs --tail=40 app 2>&1 >&2 || true
            die "app did not become healthy — check logs above"
        fi
        sleep 3
    done
    log "App is healthy"
}

compose_data_volume_exists() {
    local suffix="$1"
    $COMPOSE volume ls -q 2>/dev/null | grep -qE "(^|_)${suffix}$" || return 1
}

data_volumes_exist() {
    compose_data_volume_exists mysql_data \
        || compose_data_volume_exists minio_data \
        || compose_data_volume_exists storage_data
}

bootstrap_env_file() {
    local example="$ROOT_DIR/.env.example"

    [[ -f "$ROOT_DIR/.env" ]] && return 0
    [[ -f "$example" ]] || die "missing .env.example — cannot bootstrap .env"

    cp "$example" "$ROOT_DIR/.env"
    log "Created .env from .env.example (--bootstrap-env)"

    if data_volumes_exist; then
        echo "" >&2
        echo "warning: Docker data volumes already exist. DB/MinIO passwords in the new" >&2
        echo "         .env may not match initialized volumes. Restore .env.backup.* if" >&2
        echo "         you have one, or run ./scripts/install.sh to regenerate secrets and" >&2
        echo "         use --sync-db-password on repair if the app fails to connect." >&2
        echo "" >&2
    fi
}

print_result() {
    local env_file="$1"
    local app_url
    app_url="$(read_env_var APP_URL "$env_file")"
    app_url="${app_url%/}"

    echo ""
    echo "  ┌──────────────────────────────────────────────────────────────┐"
    echo "  │  Repair complete                                             │"
    echo "  └──────────────────────────────────────────────────────────────┘"
    echo ""
    echo "  Open in your browser:  ${app_url}"
    echo ""
    if [[ "$ACCESS_MODE" == "https" ]]; then
        echo "  Accept the self-signed certificate warning (Advanced → proceed)."
        echo "  Do NOT use https://…:8000 — TLS is on port 443 via Caddy."
    else
        echo "  Use http:// (not https://) and include :8000 in the URL."
        echo "  For HTTPS later: ./scripts/repair.sh --mode https --ip $(detect_lan_ip)"
    fi
    echo ""
    echo "  Verify:  ${COMPOSE} exec app curl -fsS http://127.0.0.1:8000/up"
    echo ""
}

main() {
    parse_args "$@"

    ROOT_DIR="$(detect_install_dir)"
    cd "$ROOT_DIR"
    [[ -f compose.yaml ]] || die "compose.yaml not found in ${ROOT_DIR}"

    COMPOSE="${COMPOSE:-$ROOT_DIR/scripts/compose.sh}"

    if [[ ! -f .env ]]; then
        if [[ "$BOOTSTRAP_ENV" -eq 1 ]]; then
            bootstrap_env_file
        else
            die ".env missing in ${ROOT_DIR} — restore .env, run ./scripts/install.sh, or pass --bootstrap-env"
        fi
    fi

    if [[ "$NON_INTERACTIVE" -eq 0 && ! -t 0 ]]; then
        NON_INTERACTIVE=1
    fi

    ensure_ownership "$ROOT_DIR"

    local ip
    ip="$(detect_lan_ip)"

    if [[ "$SKIP_ENV" -eq 0 ]]; then
        if [[ "$ACCESS_MODE" == "http" ]]; then
            configure_http_access "$ROOT_DIR/.env" "$ip"
        else
            configure_https_access "$ROOT_DIR/.env" "$ip"
        fi
    fi

    log "Starting / rebuilding stack (volumes preserved)"
    $COMPOSE up -d --build

    if [[ "$SYNC_DB" -eq 1 ]]; then
        wait_for_postgres "$ROOT_DIR/.env"
        sync_db_password "$ROOT_DIR/.env"
    fi

    rebuild_assets_if_needed "$REBUILD_ASSETS"

    log "Restarting app and workers to pick up .env changes"
    $COMPOSE restart app worker scheduler 2>/dev/null || $COMPOSE restart app

    wait_for_app_health
    print_result "$ROOT_DIR/.env"
}

main "$@"
