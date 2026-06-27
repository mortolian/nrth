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
#   --auto-deploy        Set up GitHub Actions self-hosted runner (label: nrth-server)
#   --non-interactive    Skip env prompts; use generated defaults
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

# Laravel requires a scheme; users often enter "books.example.com" without http(s)://.
normalize_app_url() {
    local url="$1"
    local default_scheme="${2:-http}"

    url="${url#"${url%%[![:space:]]*}"}"
    url="${url%"${url##*[![:space:]]}"}"
    [[ -n "$url" ]] || die "APP_URL cannot be empty"

    if [[ ! "$url" =~ ^https?:// ]]; then
        echo "warning: APP_URL missing http:// or https:// — using ${default_scheme}://${url}" >&2
        url="${default_scheme}://${url}"
    fi

    local host="${url#*://}"
    host="${host%%/*}"
    host="${host%%:*}"
    [[ -n "$host" ]] || die "APP_URL has no host (got: ${url})"

    printf '%s' "$url"
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
    COMPOSE="${COMPOSE:-docker compose}"
    if [[ "$COMPOSE" == "docker compose" ]] && ! docker_accessible; then
        if command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
            COMPOSE="sudo docker compose"
            log "Using sudo for Docker (log out/in after install, or stay on sudo docker compose)"
        fi
    fi
    export COMPOSE
}

compose_hint_for_user() {
    if docker_accessible; then
        echo "docker compose"
    elif command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
        echo "sudo docker compose"
    else
        echo "docker compose"
    fi
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

    db_pass="$(gen_secret)"
    minio_pass="$(gen_secret)"
    aws_key="nrth$(gen_secret | tr '[:upper:]' '[:lower:]' | head -c 8)"
    aws_secret="$(gen_secret)"

    if [[ "$MODE" == "production" ]]; then
        set_env_var APP_ENV production "$env_file"
        set_env_var APP_DEBUG false "$env_file"
        if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
            app_url="http://localhost:8000"
        else
            read -r -p "Public APP_URL [https://books.example.com]: " app_url
            app_url="${app_url:-https://books.example.com}"
        fi
    else
        set_env_var APP_ENV local "$env_file"
        set_env_var APP_DEBUG true "$env_file"
        if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
            app_url="http://localhost:8000"
        else
            read -r -p "APP_URL [http://localhost:8000]: " app_url
            app_url="${app_url:-http://localhost:8000}"
        fi
    fi

    local default_scheme="http"
    [[ "$MODE" == "production" ]] && default_scheme="https"
    app_url="$(normalize_app_url "$app_url" "$default_scheme")"

    set_env_var APP_URL "$app_url" "$env_file"
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
    log "Running interactive installer (admin user + default chart of accounts)"
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
    local app_port compose_hint target_user
    app_port="$(grep -E '^APP_PORT=' "$ROOT_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo 8000)"
    compose_hint="$(compose_hint_for_user)"
    target_user="${SUDO_USER:-${USER:-}}"
    echo ""
    echo "Installation complete."
    echo "  Open: http://localhost:${app_port}"
    if [[ "$NON_INTERACTIVE" -eq 1 ]] && ! users_exist; then
        echo "  Finish setup: cd ${ROOT_DIR} && ${compose_hint} exec -it app php artisan app:install"
    fi
    echo "  Updates: ${ROOT_DIR}/scripts/deploy.sh"
    if [[ "$DOCKER_GROUP_ADDED" -eq 1 && -n "$target_user" && "$target_user" != "root" ]]; then
        echo "  Docker CLI: log out and back in as ${target_user} to use docker without sudo,"
        echo "              or prefix commands with sudo (e.g. ${compose_hint} ps)"
    elif [[ "$(id -u)" -ne 0 ]] && ! docker_accessible; then
        echo "  Docker CLI: use ${compose_hint} (permission denied on /var/run/docker.sock without sudo or docker group)"
    fi
    if [[ "$MODE" == "production" ]]; then
        echo "  Production checklist: docs/SELF_HOST.md"
    fi
}

main() {
    parse_args "$@"

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

    # Re-run on an existing install: pull and apply updates.
    if [[ -f .env ]] && stack_running && users_exist; then
        log "Existing installation detected — running deploy.sh"
        local deploy_mode="dev"
        [[ "$MODE" == "production" ]] && deploy_mode="production"
        "$ROOT_DIR/scripts/deploy.sh" "$deploy_mode"
        if [[ "$AUTO_DEPLOY" -eq 1 ]]; then
            setup_auto_deploy
        fi
        exit 0
    fi

    configure_env
    ensure_app_key

    log "Building and starting containers (first run may take several minutes)"
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

    print_success
}

main "$@"
