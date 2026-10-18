#!/usr/bin/env bash
# Host-side health check for deploy.me and the infra it depends on (the shared
# stack, or its own: ./.env says which). Read-only. Ends by running
# `php artisan deployme:doctor` inside the worker.
#
#   bin/doctor.sh      (or: make doctor)
set -uo pipefail
. "$(dirname "$0")/lib.sh"
cd "$DEPLOYME_ROOT" || exit 1

STACK=$(deployme_stack)
FLAVOUR=$(deployme_flavour)
NETWORK=$(deployme_env DEPLOYME_NETWORK); NETWORK=${NETWORK:-proxy-network}
PG=$(deployme_pg_container)
EDGE=$(deployme_edge_container)
CACHE=$(deployme_cache_container)
CA=$(deployme_ca_file)
URL=https://deployme.localhost
failures=0

pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; failures=$((failures + 1)); }
check() { local label=$1; shift; if "$@" >/dev/null 2>&1; then pass "$label"; else fail "$label"; fi; }
running() { [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" = true ]; }
loopback_only() {
  local c
  for c in "${INFRA[@]}"; do docker port "$c" 2>/dev/null; done | grep -Eq '0\.0\.0\.0|\[::\]' && return 1
  return 0
}
ca_present() { [ -s "$CA" ] || bin/trust-ca --print; }
alias_owner_ok() {
  # No foreign container may hold a deployme-* name on proxy-network.
  docker network inspect "$NETWORK" -f '{{range .Containers}}{{.Name}} {{end}}' | tr ' ' '\n' \
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

MODE=$(deployme_mode)
if [ "$MODE" = prod ]; then
  echo "Mode: production (the code is baked into the image: make build && make up after a change)"
else
  echo "Mode: development (the code is mounted from src/api)"
fi

# The slim flavour runs no Postgres or Valkey: only the edge is infra.
if [ "$FLAVOUR" = slim ]; then INFRA=("$EDGE"); else INFRA=("$PG" "$CACHE" "$EDGE"); fi
# test -s first: sqlite3 would create an empty database where none is.
sqlite_ok() { docker exec deployme-web sh -c 'test -s "$1" && sqlite3 "$1" "pragma quick_check"' sh "$DEPLOYME_SQLITE_PATH" | grep -qx ok; }

echo "Infra ($FLAVOUR flavour, $STACK stack, $(deployme_os))"
check "$NETWORK network exists" docker network inspect "$NETWORK"
for c in "${INFRA[@]}"; do check "$c is running" running "$c"; done
check "infra ports are bound to 127.0.0.1 only" loopback_only
if [ "$FLAVOUR" = slim ]; then
  check "the SQLite database is there ($DEPLOYME_SQLITE_FILE)" test -s "$DEPLOYME_SQLITE_FILE"
else
  check "role deployme exists" sh -c "docker exec $PG psql -U postgres -tAc \"select 1 from pg_roles where rolname = 'deployme'\" | grep -q 1"
fi
check "the edge's local CA is on this machine ($CA)" ca_present

echo "deploy.me"
for c in deployme-web deployme-worker deployme-reverb; do check "$c is running" running "$c"; done
# The mode ./.env names is the one the containers were started in (else: make up).
# A container from before modes existed has no DEPLOYME_MODE: that was dev.
in_mode() {
  local running_mode
  running_mode=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' deployme-web 2>/dev/null | sed -n 's/^DEPLOYME_MODE=//p')
  [ "${running_mode:-dev}" = "$MODE" ]
}
check "deployme-web runs in $MODE mode" in_mode
# The flavour ./.env names is the one the containers were started in, too.
in_flavour() {
  local running_flavour
  running_flavour=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' deployme-web 2>/dev/null | sed -n 's/^DEPLOYME_FLAVOUR=//p')
  [ "${running_flavour:-full}" = "$FLAVOUR" ]
}
check "deployme-web runs the $FLAVOUR flavour" in_flavour
[ "$FLAVOUR" = slim ] && check "SQLite says the database is sound (quick_check)" sqlite_ok
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
