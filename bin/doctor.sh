#!/usr/bin/env bash
# Host-side health check for deploy.me and the shared infra it depends on.
# Read-only. Ends by running `php artisan deployme:doctor` inside the worker.
#
#   bin/doctor.sh      (or: make doctor)
set -uo pipefail
cd "$(dirname "$0")/.."

INFRA="${INFRA_DIR:-$HOME/Personal/infra}"
CA="$INFRA/caddy-local-root.crt"
URL=https://deployme.localhost
failures=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; failures=$((failures + 1)); }
check() { local label=$1; shift; if "$@" >/dev/null 2>&1; then pass "$label"; else fail "$label"; fi; }
running() { [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" = true ]; }
loopback_only() { ! { docker port caddy; docker port postgres; docker port valkey; } 2>/dev/null | grep -Eq '0\.0\.0\.0|\[::\]'; }
alias_owner_ok() {
  # No foreign container may hold a deployme-* name on proxy-network.
  docker network inspect proxy-network -f '{{range .Containers}}{{.Name}} {{end}}' | tr ' ' '\n' \
    | grep -E '^deployme-' | while read -r name; do
        [ "$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$name")" = deployme ] || exit 1
      done
}
ws_upgrade() {
  local key
  key=$(grep -E '^REVERB_APP_KEY=' src/api/.env | cut -d= -f2- | tr -d '"')
  [ -n "$key" ] || return 1
  # Reverb only accepts the app's own origin (REVERB_ALLOWED_ORIGINS), like a browser sends.
  # After a 101 the socket stays open until --max-time, so curl exits 28 by design:
  # read the status code instead of curl's exit code (pipefail would fail on it).
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --http1.1 --max-time 3 --cacert "$CA" \
    -H "Origin: $URL" -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
    -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' "$URL/app/$key?protocol=7" || true)
  [ "$code" = 101 ]
}

echo "Shared infra"
check "proxy-network exists" docker network inspect proxy-network
for c in postgres valkey caddy; do check "$c is running" running "$c"; done
check "infra ports are bound to 127.0.0.1 only" loopback_only
check "role deployme exists" sh -c "docker exec postgres psql -U postgres -tAc \"select 1 from pg_roles where rolname = 'deployme'\" | grep -q 1"

echo "deploy.me"
for c in deployme-web deployme-worker deployme-reverb; do check "$c is running" running "$c"; done
check "no foreign container holds a deployme-* name" alias_owner_ok
check "$URL/up answers over TLS" curl -fsS -o /dev/null --max-time 5 --cacert "$CA" "$URL/up"
check "websocket upgrade reaches Reverb through the edge" ws_upgrade

echo "Inside the containers"
if running deployme-worker; then
  docker exec deployme-worker php artisan deployme:doctor || failures=$((failures + 1))
else
  fail "deployme-worker isn't running, skipped"
fi

echo
if [ "$failures" -eq 0 ]; then echo "All checks passed."; else echo "$failures check(s) failed."; exit 1; fi
