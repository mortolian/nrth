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
        usermod -aG docker "$target_user" || true
        log "Added user '$target_user' to the docker group (log out/in if docker permission denied)"
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

wait_for_app_health() {
    log "Waiting for the app container to become healthy..."
    local tries=60
    until $COMPOSE exec -T app curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1; do
        tries=$((tries - 1))
        if [[ "$tries" -le 0 ]]; then
            die "app did not become ready in time — check: docker compose logs app"
        fi
        sleep 3
    done
}

users_exist() {
    $COMPOSE exec -T app php artisan tinker --execute='exit(App\Models\User::query()->exists() ? 0 : 1);' >/dev/null 2>&1
}

stack_running() {
    $COMPOSE ps --status running app 2>/dev/null | grep -q app
}

ensure_app_key() {
    if ! grep -qE '^APP_KEY=base64:.+' "$ROOT_DIR/.env" 2>/dev/null; then
        log "Generating APP_KEY"
        $COMPOSE run --rm -T app php artisan key:generate --force --no-interaction
    fi
}

run_first_install() {
    log "Running interactive installer (admin user + default chart of accounts)"
    $COMPOSE exec app php artisan app:install
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
    local app_port
    app_port="$(grep -E '^APP_PORT=' "$ROOT_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo 8000)"
    echo ""
    echo "Installation complete."
    echo "  Open: http://localhost:${app_port}"
    echo "  Updates: ${ROOT_DIR}/scripts/deploy.sh"
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

    COMPOSE="${COMPOSE:-docker compose}"
    export COMPOSE

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

    log "Building and starting containers (first run may take several minutes)"
    $COMPOSE up -d --build

    wait_for_app_health
    ensure_app_key

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
