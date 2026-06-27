#!/usr/bin/env bash
#
# Unified nrth installation for Ubuntu 22.04/24.04 (Docker Compose stack).
#
# Usage examples:
#
#   # Fresh Ubuntu server (pipe args after --)
#   curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash
#   curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh | sudo bash -s -- --production --auto-deploy
#
#   # From a clone
#   ./scripts/install.sh
#   ./scripts/install.sh --auto-deploy --install-dir /opt/nrth
#   ./scripts/install.sh --production --install-dir /opt/nrth
#
# Flags:
#   --repo-url URL       Git remote (default: https://github.com/mortolian/nrth.git)
#   --install-dir PATH   Install location (default: repo root or /opt/nrth when piping)
#   --branch NAME        Git branch (default: master)
#   --production         APP_ENV=production, APP_DEBUG=false
#   --dev                Dev/staging defaults (default)
#   --with-caddy         Enable Compose Caddy TLS proxy (default for --production)
#   --no-caddy           Do not start the optional Caddy reverse proxy
#   --auto-deploy        Set up GitHub Actions self-hosted runner (label: nrth-server)
#   --non-interactive    Skip env prompts; use generated defaults
#   --allow-http         Permit plain HTTP (pragmatic LAN dev; sets APP_ALLOW_HTTP=true)
#   --lan                Shorthand: --dev --allow-http --no-caddy (fastest LAN access)
#   --lan-ip ADDR        LAN IP for APP_URL (with --lan or --allow-http)
#   --repair             Delegate to scripts/repair.sh (non-destructive fix)
#   -h, --help           Show help
#
# Environment:
#   GITHUB_RUNNER_TOKEN  Short-lived runner registration token (for --auto-deploy)
#   COMPOSE              Override compose command (default: docker compose)

set -euo pipefail

DEFAULT_REPO_URL="https://github.com/mortolian/nrth.git"
DEFAULT_GITHUB_REPO="https://github.com/mortolian/nrth"
DEFAULT_BRANCH="master"
DEFAULT_INSTALL_DIR="/opt/nrth"
DEFAULT_RUNNER_NAME="nrth-server"
DEFAULT_RUNNER_LABEL="nrth-server"

DOCKER_GROUP_ADDED=0

REPO_URL="$DEFAULT_REPO_URL"
GITHUB_REPO="$DEFAULT_GITHUB_REPO"
RUNNER_NAME="$DEFAULT_RUNNER_NAME"
RUNNER_LABEL="$DEFAULT_RUNNER_LABEL"
INSTALL_DIR=""
BRANCH="$DEFAULT_BRANCH"
MODE="dev"
AUTO_DEPLOY=0
NON_INTERACTIVE=0
WITH_CADDY=-1
ALLOW_HTTP=0
LAN_IP=""
REPAIR=0

usage() {
    sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'
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
            --repo-url)
                REPO_URL="${2:?--repo-url requires a value}"
                shift 2
                ;;
            --install-dir)
                INSTALL_DIR="${2:?--install-dir requires a value}"
                shift 2
                ;;
            --branch)
                BRANCH="${2:?--branch requires a value}"
                shift 2
                ;;
            --production)
                MODE="production"
                shift
                ;;
            --dev)
                MODE="dev"
                shift
                ;;
            --auto-deploy)
                AUTO_DEPLOY=1
                shift
                ;;
            --non-interactive)
                NON_INTERACTIVE=1
                shift
                ;;
            --with-caddy)
                WITH_CADDY=1
                shift
                ;;
            --no-caddy)
                WITH_CADDY=0
                shift
                ;;
            --allow-http)
                ALLOW_HTTP=1
                shift
                ;;
            --lan)
                MODE="dev"
                ALLOW_HTTP=1
                WITH_CADDY=0
                shift
                ;;
            --lan-ip)
                LAN_IP="${2:?--lan-ip requires a value}"
                shift 2
                ;;
            --repair)
                REPAIR=1
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

detect_root_from_script() {
    local dir
    dir="$(script_path)"
    if [[ -f "$dir/../compose.yaml" ]]; then
        cd "$dir/.." && pwd
        return 0
    fi
    return 1
}

is_ubuntu() {
    [[ -f /etc/os-release ]] && grep -qiE 'ubuntu|debian' /etc/os-release
}

require_root_for_docker() {
    if [[ "$(id -u)" -ne 0 ]]; then
        if command -v sudo >/dev/null 2>&1; then
            echo "Re-running with sudo for Docker and system packages..."
            if [[ -f "${BASH_SOURCE[0]:-}" ]]; then
                exec sudo -E bash "${BASH_SOURCE[0]}" "$@"
            else
                exec sudo -E bash -s -- "$@"
            fi
        fi
        die "run as root or with sudo on a clean Ubuntu server"
    fi
}

gen_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 24 | tr -d '/+=' | head -c 24
    else
        tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24
    fi
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

# Postgres/MinIO only apply *_PASSWORD on first volume init; rotating .env breaks re-runs.
compose_data_volume_exists() {
    local suffix="$1"
    $COMPOSE volume ls -q 2>/dev/null | grep -qE "(^|_)${suffix}$"
}

preserve_or_gen_secret() {
    local key="$1"
    local env_file="$2"
    local volume_suffix="$3"
    local existing

    existing="$(read_env_var "$key" "$env_file")"
    if [[ -n "$existing" ]] && compose_data_volume_exists "$volume_suffix"; then
        log "Preserving existing ${key} (${volume_suffix} volume already initialized)"
        printf '%s' "$existing"
        return 0
    fi

    gen_secret
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
    printf '%s' "$ip"
}

# Laravel requires a scheme; users often enter "books.example.com" without http(s)://.
normalize_app_url() {
    local url="$1"
    local default_scheme="${2:-https}"

    url="${url#"${url%%[![:space:]]*}"}"
    url="${url%"${url##*[![:space:]]}"}"
    [[ -n "$url" ]] || die "APP_URL cannot be empty"

    if [[ ! "$url" =~ ^https?:// ]]; then
        echo "warning: APP_URL missing http:// or https:// — using ${default_scheme}://${url}" >&2
        url="${default_scheme}://${url}"
    fi

    if [[ "$url" =~ ^http:// ]]; then
        if [[ "$ALLOW_HTTP" -eq 1 ]]; then
            printf '%s' "$url"
            return 0
        fi
        if [[ "$MODE" == "production" ]]; then
            die "APP_URL must use https:// — plain HTTP is not permitted for production (use --allow-http only for LAN dev)"
        fi
        echo "warning: converting http:// APP_URL to https:// (pass --allow-http for plain HTTP on LAN)" >&2
        url="https://${url#http://}"
    fi

    local host="${url#*://}"
    host="${host%%/*}"
    host="${host%%:*}"
    [[ -n "$host" ]] || die "APP_URL has no host (got: ${url})"

    printf '%s' "$url"
}

app_url_host() {
    local url="$1"
    local host="${url#*://}"
    host="${host%%/*}"
    host="${host%%:*}"
    printf '%s' "$host"
}

is_ip_address() {
    [[ "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
}

strip_app_url_port_for_proxy() {
    local url="$1"
    local scheme="${url%%://*}"
    local rest="${url#*://}"
    local host="${rest%%/*}"
    local path="${rest#"$host"}"
    local port=""

    if [[ "$host" == *:* ]]; then
        port="${host##*:}"
        host="${host%%:*}"
        if [[ "$port" == "8000" ]]; then
            url="${scheme}://${host}${path}"
        fi
    fi

    printf '%s' "$url"
}

configure_caddy_proxy() {
    local env_file="$1"
    local app_url="$2"
    local host

    host="$(app_url_host "$app_url")"
    app_url="$(strip_app_url_port_for_proxy "$app_url")"

    set_env_var APP_URL "$app_url" "$env_file"
    set_env_var COMPOSE_PROFILES proxy "$env_file"
    set_env_var CADDY_SITE "$host" "$env_file"
    if is_ip_address "$host"; then
        set_env_var CADDY_TLS internal "$env_file"
        log "Caddy proxy enabled (self-signed TLS for LAN IP ${host})"
    else
        set_env_var CADDY_TLS off "$env_file"
        log "Caddy proxy enabled (automatic TLS for ${host})"
    fi
}

resolve_with_caddy_default() {
    if [[ "$WITH_CADDY" -ne -1 ]]; then
        return 0
    fi
    if [[ "$MODE" == "production" ]]; then
        WITH_CADDY=1
    else
        WITH_CADDY=0
    fi
}

gen_app_key() {
    if command -v openssl >/dev/null 2>&1; then
        echo "base64:$(openssl rand -base64 32)"
    else
        die "openssl is required to generate APP_KEY"
    fi
}

install_system_packages() {
    if ! command -v git >/dev/null 2>&1 || ! command -v curl >/dev/null 2>&1; then
        log "Installing git and curl"
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git curl ca-certificates
    fi
}

install_docker() {
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        log "Docker Engine and Compose plugin already installed"
        return
    fi

    log "Installing Docker Engine and Compose plugin (get.docker.com)"
    install_system_packages
    curl -fsSL https://get.docker.com | sh

    local target_user="${SUDO_USER:-${USER:-}}"
    if [[ -n "$target_user" && "$target_user" != "root" ]]; then
        if usermod -aG docker "$target_user" 2>/dev/null; then
            DOCKER_GROUP_ADDED=1
            log "Added user '$target_user' to the docker group (log out/in to use docker without sudo)"
        fi
    fi
}

docker_accessible() {
    docker info >/dev/null 2>&1
}

configure_compose() {
    if [[ -n "${COMPOSE:-}" ]]; then
        export COMPOSE
        return
    fi

    if [[ -f "$ROOT_DIR/scripts/compose.sh" ]]; then
        COMPOSE="$ROOT_DIR/scripts/compose.sh"
    elif docker_accessible; then
        COMPOSE="docker compose"
    elif command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
        COMPOSE="sudo docker compose"
        log "Using sudo for Docker (log out/in after install, or use scripts/compose.sh)"
    else
        COMPOSE="docker compose"
    fi
    export COMPOSE
}

compose_hint_for_user() {
    echo "./scripts/compose.sh"
}

# curl | sudo bash clones as root; the invoking user (SUDO_USER) needs to own the tree for git pull.
ensure_install_dir_ownership() {
    local target_user="${SUDO_USER:-}"
    local dir="${1:-$INSTALL_DIR}"

    if [[ "$(id -u)" -ne 0 ]] || [[ -z "$target_user" || "$target_user" == "root" ]]; then
        return 0
    fi
    [[ -d "$dir" ]] || return 0

    log "Setting ownership of ${dir} to ${target_user} (git without sudo)"
    chown -R "${target_user}:${target_user}" "$dir"
}

ensure_install_dir() {
    if [[ -z "$INSTALL_DIR" ]]; then
        if ROOT="$(detect_root_from_script 2>/dev/null)"; then
            INSTALL_DIR="$ROOT"
        else
            INSTALL_DIR="$DEFAULT_INSTALL_DIR"
        fi
    fi

    if [[ ! -d "$INSTALL_DIR/.git" && ! -f "$INSTALL_DIR/compose.yaml" ]]; then
        log "Cloning ${REPO_URL} (branch ${BRANCH}) into ${INSTALL_DIR}"
        mkdir -p "$(dirname "$INSTALL_DIR")"
        if [[ ! -d "$INSTALL_DIR" ]]; then
            git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR"
        else
            die "install directory exists but is not a clone: $INSTALL_DIR"
        fi
    elif [[ -d "$INSTALL_DIR/.git" ]]; then
        log "Using existing clone at ${INSTALL_DIR}"
    fi

    [[ -f "$INSTALL_DIR/compose.yaml" ]] || die "compose.yaml not found in ${INSTALL_DIR}"

    if [[ -d "$INSTALL_DIR/.git" ]]; then
        ensure_install_dir_ownership
    fi
}

configure_env() {
    local env_file="$ROOT_DIR/.env"
    local example="$ROOT_DIR/.env.example"

    if [[ ! -f "$env_file" ]]; then
        [[ -f "$example" ]] || die "missing .env.example"
        cp "$example" "$env_file"
        log "Created .env from .env.example"
    fi

    local db_pass minio_pass aws_key aws_secret app_url

    db_pass="$(preserve_or_gen_secret DB_PASSWORD "$env_file" mysql_data)"
    minio_pass="$(preserve_or_gen_secret MINIO_ROOT_PASSWORD "$env_file" minio_data)"
    aws_key="$(read_env_var AWS_ACCESS_KEY_ID "$env_file")"
    aws_secret="$(read_env_var AWS_SECRET_ACCESS_KEY "$env_file")"
    if [[ -n "$aws_key" && -n "$aws_secret" ]] && compose_data_volume_exists minio_data; then
        log "Preserving existing MinIO client credentials (minio_data volume already initialized)"
    else
        aws_key="nrth$(gen_secret | tr '[:upper:]' '[:lower:]' | head -c 8)"
        aws_secret="$(gen_secret)"
    fi

    if [[ "$MODE" == "production" ]]; then
        set_env_var APP_ENV production "$env_file"
        set_env_var APP_DEBUG false "$env_file"
        resolve_with_caddy_default
        if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
            if [[ "$WITH_CADDY" -eq 1 ]]; then
                app_url="https://localhost"
            else
                app_url="https://localhost:8000"
            fi
        else
            read -r -p "Public APP_URL [https://books.example.com]: " app_url
            app_url="${app_url:-https://books.example.com}"
        fi
    else
        set_env_var APP_ENV local "$env_file"
        set_env_var APP_DEBUG true "$env_file"
        resolve_with_caddy_default
        if [[ "$ALLOW_HTTP" -eq 1 ]]; then
            local lan_ip
            lan_ip="$(detect_lan_ip)"
            if [[ -n "$lan_ip" ]]; then
                app_url="http://${lan_ip}:8000"
            else
                app_url="http://localhost:8000"
            fi
            if [[ "$NON_INTERACTIVE" -eq 0 ]]; then
                read -r -p "APP_URL [${app_url}]: " custom_url
                app_url="${custom_url:-$app_url}"
            fi
        elif [[ "$NON_INTERACTIVE" -eq 1 ]]; then
            app_url="https://localhost:8000"
        else
            read -r -p "APP_URL [https://localhost:8000]: " app_url
            app_url="${app_url:-https://localhost:8000}"
        fi
    fi

    local url_scheme="https"
    [[ "$ALLOW_HTTP" -eq 1 ]] && url_scheme="http"
    app_url="$(normalize_app_url "$app_url" "$url_scheme")"

    if [[ "$WITH_CADDY" -eq 1 ]]; then
        configure_caddy_proxy "$env_file" "$app_url"
    else
        set_env_var APP_URL "$app_url" "$env_file"
    fi
    if [[ "$ALLOW_HTTP" -eq 1 ]]; then
        set_env_var APP_ALLOW_HTTP true "$env_file"
        set_env_var APP_FORCE_HTTPS false "$env_file"
    else
        set_env_var APP_FORCE_HTTPS true "$env_file"
        set_env_var APP_ALLOW_HTTP false "$env_file"
    fi
    set_env_var TRUSTED_PROXIES "*" "$env_file"
    set_env_var DB_CONNECTION pgsql "$env_file"
    set_env_var DB_HOST 127.0.0.1 "$env_file"
    set_env_var DB_PORT 5432 "$env_file"
    set_env_var DB_DATABASE nrthapp "$env_file"
    set_env_var DB_USERNAME dbuser "$env_file"
    set_env_var DB_PASSWORD "$db_pass" "$env_file"
    set_env_var QUEUE_CONNECTION redis "$env_file"
    set_env_var CACHE_STORE redis "$env_file"
    set_env_var SESSION_DRIVER redis "$env_file"
    set_env_var REDIS_HOST 127.0.0.1 "$env_file"
    set_env_var REDIS_PORT 6379 "$env_file"
    set_env_var MINIO_ROOT_USER minio "$env_file"
    set_env_var MINIO_ROOT_PASSWORD "$minio_pass" "$env_file"
    set_env_var AWS_ACCESS_KEY_ID "$aws_key" "$env_file"
    set_env_var AWS_SECRET_ACCESS_KEY "$aws_secret" "$env_file"
    set_env_var AWS_BUCKET nrth "$env_file"
    set_env_var AWS_DEFAULT_REGION us-east-1 "$env_file"

    log "Configured .env for Docker Compose (${MODE} mode)"
}

show_app_startup_diagnostics() {
    echo "" >&2
    echo "--- docker compose ps app ---" >&2
    $COMPOSE ps app 2>&1 >&2 || true
    echo "" >&2
    echo "--- last 50 lines: docker compose logs app ---" >&2
    $COMPOSE logs --tail=50 app 2>&1 >&2 || true
    echo "" >&2
    echo "--- probe http://127.0.0.1:8000/up inside app container ---" >&2
    $COMPOSE exec -T app curl -sv --max-time 5 http://127.0.0.1:8000/up 2>&1 >&2 || true
    echo "" >&2
    if ! grep -qE '^APP_KEY=base64:.+' "$ROOT_DIR/.env" 2>/dev/null; then
        echo "hint: APP_KEY is missing — Laravel will not boot until it is set." >&2
    fi
    local raw_url
    raw_url="$(grep -E '^APP_URL=' "$ROOT_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
    if [[ -n "$raw_url" && ! "$raw_url" =~ ^https?:// ]]; then
        echo "hint: APP_URL should include http:// or https:// (current: ${raw_url})" >&2
    fi
}

wait_for_app_health() {
    log "Waiting for the app container to become healthy..."
    # First boot may install composer/npm deps, run migrations, and start Octane (compose start_period is 120s).
    local tries=120
    until $COMPOSE exec -T app curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1; do
        tries=$((tries - 1))
        if [[ "$tries" -le 0 ]]; then
            show_app_startup_diagnostics
            die "app did not become ready in time — see diagnostics above"
        fi
        sleep 3
    done
    log "App is healthy"
}

users_exist() {
    $COMPOSE exec -T app php artisan tinker --execute='exit(App\Models\User::query()->exists() ? 0 : 1);' >/dev/null 2>&1
}

stack_running() {
    $COMPOSE ps --status running app 2>/dev/null | grep -q app
}

app_healthy() {
    $COMPOSE exec -T app curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1
}

recover_broken_stack() {
    [[ -f "$ROOT_DIR/.env" ]] || return 1
    data_volumes_exist || return 1
    stack_running && app_healthy && return 1

    log "Broken or unhealthy stack detected — running repair (data preserved)"
    local -a repair_args=(--install-dir "$ROOT_DIR" --non-interactive)
    [[ "$ALLOW_HTTP" -eq 1 ]] && repair_args+=(--mode http) || repair_args+=(--mode https)
    [[ -n "$LAN_IP" ]] && repair_args+=(--ip "$LAN_IP")
    "$ROOT_DIR/scripts/repair.sh" "${repair_args[@]}"
    exit 0
}

data_volumes_exist() {
    compose_data_volume_exists mysql_data \
        || compose_data_volume_exists minio_data \
        || compose_data_volume_exists storage_data
}

is_configured_install() {
    [[ -f "$ROOT_DIR/.env" ]] \
        && grep -qE '^APP_KEY=base64:.+' "$ROOT_DIR/.env" 2>/dev/null \
        && data_volumes_exist
}

log_existing_install_safety() {
    echo ""
    log "Existing installation detected"
    echo "  Preserved: Docker volumes (database, redis, minio, storage, vendor, node_modules)"
    echo "  Preserved: DB and MinIO credentials in .env (when volumes are already initialized)"
    echo "  Will run:  code update, incremental migrations, queue/cache refresh"
    echo "  Will NOT:   compose down -v, migrate:fresh, password rotation, or app:install"
    echo ""
}

handle_existing_install() {
    is_configured_install || return 1

    if ! stack_running; then
        log "Existing data volumes found — starting stack (volumes preserved, no down -v)"
        $COMPOSE up -d --build
        wait_for_app_health
    fi

    if ! users_exist; then
        log "Existing volumes found but no admin user yet — continuing first-time setup"
        log "Database and file storage will not be wiped; secrets in .env are preserved"
        return 1
    fi

    log_existing_install_safety
    local deploy_mode="dev"
    [[ "$MODE" == "production" ]] && deploy_mode="production"
    "$ROOT_DIR/scripts/deploy.sh" "$deploy_mode"
    if [[ "$AUTO_DEPLOY" -eq 1 ]]; then
        setup_auto_deploy
    fi
    ensure_install_dir_ownership "$ROOT_DIR"
    exit 0
}

ensure_app_key() {
    if grep -qE '^APP_KEY=base64:.+' "$ROOT_DIR/.env" 2>/dev/null; then
        return 0
    fi
    log "Generating APP_KEY"
    set_env_var APP_KEY "$(gen_app_key)" "$ROOT_DIR/.env"
}

run_first_install() {
    if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
        log "Skipping interactive app:install (non-interactive). Run manually:"
        echo "  cd ${ROOT_DIR} && $(compose_hint_for_user) exec -it app php artisan app:install"
        return 0
    fi
    log "Running interactive installer (admin user and company)"
    $COMPOSE exec -it app php artisan app:install
}

print_auto_deploy_manual_steps() {
    cat <<EOF

GitHub Actions self-hosted runner (auto-deploy on push to master)
-----------------------------------------------------------------

The workflow .github/workflows/deploy-personal-server.yml runs:
  ${ROOT_DIR}/scripts/deploy.sh

1. Open: ${GITHUB_REPO}/settings/actions/runners/new
2. Select Linux x64 and copy the registration token.
3. On this server, re-run install with the token:

   GITHUB_RUNNER_TOKEN=<token> ${ROOT_DIR}/scripts/install.sh --auto-deploy

4. Ensure the runner has label: ${RUNNER_LABEL}
5. Push to master — the workflow should run on this runner within ~1 minute.

Docs: ${ROOT_DIR}/docs/PERSONAL_SERVER.md

EOF
}

install_github_runner() {
    local token="$1"
    local runner_dir="${ROOT_DIR}/actions-runner"
    local arch version url

    case "$(uname -m)" in
        x86_64|amd64) arch="x64" ;;
        aarch64|arm64) arch="arm64" ;;
        *) die "unsupported architecture for GitHub runner: $(uname -m)" ;;
    esac

    version="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest | grep -Eo '"tag_name": "v[^"]+"' | head -1 | cut -d'"' -f4 | sed 's/^v//')"
    [[ -n "$version" ]] || die "could not determine GitHub Actions runner version"

    url="https://github.com/actions/runner/releases/download/v${version}/actions-runner-linux-${arch}-${version}.tar.gz"

    log "Installing GitHub Actions runner v${version} into ${runner_dir}"
    mkdir -p "$runner_dir"
    (
        cd "$runner_dir"

        if [[ ! -f ./config.sh ]]; then
            curl -fsSL -o runner.tar.gz "$url"
            tar xzf runner.tar.gz
            rm -f runner.tar.gz
        fi

        if [[ -f .runner ]]; then
            log "Runner already configured in ${runner_dir}"
        else
            ./config.sh \
                --url "$GITHUB_REPO" \
                --token "$token" \
                --name "$RUNNER_NAME" \
                --labels "$RUNNER_LABEL" \
                --unattended \
                --replace
        fi

        if [[ "$(id -u)" -eq 0 ]]; then
            ./svc.sh install
            ./svc.sh start
            log "Runner service installed and started"
        else
            log "Run the following to install the runner as a service:"
            echo "  cd ${runner_dir} && sudo ./svc.sh install && sudo ./svc.sh start"
        fi
    )
}

setup_auto_deploy() {
    log "Auto-deploy setup (GitHub Actions self-hosted runner, label: ${RUNNER_LABEL})"

    if [[ -n "${GITHUB_RUNNER_TOKEN:-}" ]]; then
        install_github_runner "$GITHUB_RUNNER_TOKEN"
        echo ""
        echo "Runner setup complete. Pushes to master will trigger ${ROOT_DIR}/scripts/deploy.sh"
        return 0
    fi

    print_auto_deploy_manual_steps

    if [[ -t 0 && -t 1 ]]; then
        read -r -p "Paste registration token now (or press Enter to finish later): " token
        if [[ -n "$token" ]]; then
            install_github_runner "$token"
            echo ""
            echo "Runner setup complete."
        fi
    fi
}

print_success() {
    local app_port compose_hint target_user app_url
    app_port="$(grep -E '^APP_PORT=' "$ROOT_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo 8000)"
    compose_hint="$(compose_hint_for_user)"
    target_user="${SUDO_USER:-${USER:-}}"
    app_url="$(grep -E '^APP_URL=' "$ROOT_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "https://localhost:${app_port}")"
    app_url="${app_url%/}"

    echo ""
    echo "  ┌──────────────────────────────────────────────────────────────┐"
    echo "  │  Installation complete                                       │"
    echo "  └──────────────────────────────────────────────────────────────┘"
    echo ""
    echo "  Application   ${app_url}"
    echo "  Install dir   ${ROOT_DIR}"
    echo ""
    echo "  Next steps"
    if [[ "$NON_INTERACTIVE" -eq 1 ]] && ! users_exist; then
        echo "  1. Create your admin account:"
        echo "       cd ${ROOT_DIR} && ${compose_hint} exec -it app php artisan app:install"
        echo "  2. Sign in and complete the setup wizard"
    else
        echo "  1. Sign in at the URL above with your admin credentials"
        echo "  2. Complete the in-app setup wizard (company details and preferences)"
    fi
    echo "  3. After upgrades: ./scripts/deploy.sh production"
    echo "     (or ./scripts/compose.sh exec app php artisan app:update)"
    if [[ "$MODE" == "production" ]]; then
        echo "  4. Production checklist: ${ROOT_DIR}/docs/SELF_HOST.md"
        if grep -qE '^COMPOSE_PROFILES=.*proxy' "$ROOT_DIR/.env" 2>/dev/null; then
            echo "  5. HTTPS via Caddy on ports 80/443 (accept browser warning for LAN self-signed certs)"
        fi
    fi
    if [[ "$DOCKER_GROUP_ADDED" -eq 1 && -n "$target_user" && "$target_user" != "root" ]]; then
        echo ""
        echo "  Docker: ${target_user} was added to the docker group."
        echo "          Use ${compose_hint} now (auto-sudo until re-login), or run:"
        echo "            newgrp docker    # apply group in this shell"
        echo "            log out/in       # permanent fix"
    elif [[ "$(id -u)" -ne 0 ]] && ! docker_accessible; then
        echo ""
        echo "  Docker: use ${compose_hint} (permission denied on /var/run/docker.sock without sudo or docker group)"
    fi
    echo ""
}

main() {
    parse_args "$@"

    if [[ "$REPAIR" -eq 1 ]]; then
        local repair_script
        repair_script="$(script_path)/repair.sh"
        [[ -f "$repair_script" ]] || die "repair.sh not found next to install.sh"
        local -a repair_args=()
        [[ -n "$INSTALL_DIR" ]] && repair_args+=(--install-dir "$INSTALL_DIR")
        [[ "$ALLOW_HTTP" -eq 1 ]] && repair_args+=(--mode http)
        [[ -n "$LAN_IP" ]] && repair_args+=(--ip "$LAN_IP")
        [[ "$NON_INTERACTIVE" -eq 1 ]] && repair_args+=(--non-interactive)
        exec "$repair_script" "${repair_args[@]}"
    fi

    local piped=0
    if ! detect_root_from_script >/dev/null 2>&1; then
        piped=1
    fi

    # curl | bash leaves stdin as the script stream; read would consume script lines.
    if [[ "$NON_INTERACTIVE" -eq 0 ]] && { [[ "$piped" -eq 1 ]] || ! [[ -t 0 ]]; }; then
        NON_INTERACTIVE=1
        log "Non-interactive mode (piped install or no TTY on stdin)"
    fi

    if [[ "$piped" -eq 1 ]] || ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        is_ubuntu || echo "warning: this script is tested on Ubuntu 22.04/24.04; continuing anyway" >&2
        if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
            if [[ "$(id -u)" -ne 0 ]]; then
                require_root_for_docker "$@"
            fi
            install_docker
        fi
    fi

    ensure_install_dir
    ROOT_DIR="$(cd "$INSTALL_DIR" && pwd)"
    cd "$ROOT_DIR"

    configure_compose

    recover_broken_stack

    handle_existing_install

    if [[ -f .env ]] && data_volumes_exist; then
        log "Existing Docker volumes detected — DB/MinIO passwords will be preserved"
    fi

    configure_env
    ensure_app_key

    log "Building and starting containers (first run may take several minutes; existing volumes are preserved)"
    $COMPOSE up -d --build

    wait_for_app_health

    if users_exist; then
        log "Users already exist — skipping app:install"
    else
        run_first_install
    fi

    if [[ "$AUTO_DEPLOY" -eq 1 ]]; then
        setup_auto_deploy
    fi

    ensure_install_dir_ownership "$ROOT_DIR"
    print_success
}

main "$@"
