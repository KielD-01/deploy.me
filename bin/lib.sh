# shellcheck shell=bash
# Shared by the bin/ scripts: where this checkout is, what machine it's on, and
# the stack mode bin/configure wrote into ./.env. Source it; it sets nothing
# outside the deployme_* and DEPLOYME_* names.
#
#   . "$(dirname "$0")/lib.sh"

DEPLOYME_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

# macos, wsl or linux.
deployme_os() {
    case "$(uname -s)" in
        Darwin) echo macos ;;
        Linux) if grep -qiE 'microsoft|wsl' /proc/version 2>/dev/null; then echo wsl; else echo linux; fi ;;
        *) echo unknown ;;
    esac
}

# One KEY=value from ./.env (or the given file), without sourcing it.
deployme_env() {
    local key=$1 file=${2:-$DEPLOYME_ROOT/.env}
    [ -f "$file" ] || return 0
    # A missing key is an empty value, not a failure (callers run under pipefail).
    { grep -E "^${key}=" "$file" || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

# shared (an existing infra stack) or standalone (compose.standalone.yml). A
# checkout that was never configured is the original setup: shared.
deployme_stack() {
    local stack
    stack=$(deployme_env DEPLOYME_STACK)
    echo "${stack:-shared}"
}

# dev (the code is mounted from src/api and edited live) or prod (the code is
# baked into the image: compose.prod.yml). A checkout that never chose is dev.
deployme_mode() {
    local mode
    mode=$(deployme_env DEPLOYME_MODE)
    echo "${mode:-dev}"
}

# Sets the app's APP_ENV, APP_DEBUG and LOG_LEVEL in src/api/.env (or the given
# file) for a mode, leaving every other line as it is. Written in place, so the
# file keeps its mode and inode (prod mounts it into the containers on its own).
deployme_app_env_for_mode() {
    local mode=$1 file=${2:-$DEPLOYME_ROOT/src/api/.env} tmp
    [ -f "$file" ] || return 0
    local app_env=local app_debug=true log_level=debug
    if [ "$mode" = prod ]; then app_env=production; app_debug=false; log_level=warning; fi
    tmp=$(mktemp)
    awk -v env="$app_env" -v debug="$app_debug" -v level="$log_level" '
        BEGIN { want["APP_ENV"] = env; want["APP_DEBUG"] = debug; want["LOG_LEVEL"] = level }
        {
            key = $0; sub(/=.*/, "", key)
            if ((key in want) && index($0, "=") > 0) { print key "=" want[key]; seen[key] = 1; next }
            print
        }
        END { for (key in want) if (!(key in seen)) print key "=" want[key] }
    ' "$file" > "$tmp" && cat "$tmp" > "$file"
    rm -f "$tmp"
}

# The containers behind the names the app uses.
deployme_pg_container() { [ "$(deployme_stack)" = standalone ] && echo deployme-postgres || echo postgres; }
deployme_edge_container() { [ "$(deployme_stack)" = standalone ] && echo deployme-caddy || echo caddy; }
deployme_cache_container() { [ "$(deployme_stack)" = standalone ] && echo deployme-valkey || echo valkey; }

# Where the edge's local root certificate is kept on this machine.
deployme_ca_file() {
    if [ "$(deployme_stack)" = standalone ]; then
        echo "$DEPLOYME_ROOT/.deployme/caddy-root.crt"
    else
        echo "${INFRA_DIR:-$HOME/Personal/infra}/caddy-local-root.crt"
    fi
}

# docker compose with this checkout's files: ./.env names them (COMPOSE_FILE);
# without one, the original single file.
deployme_compose() {
    if [ -f "$DEPLOYME_ROOT/.env" ]; then
        (cd "$DEPLOYME_ROOT" && docker compose "$@")
    else
        (cd "$DEPLOYME_ROOT" && docker compose -f docker-compose.local.yml "$@")
    fi
}

deployme_say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
deployme_ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
deployme_warn() { printf '  \033[33m!\033[0m %s\n' "$*" >&2; }
deployme_die() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }
