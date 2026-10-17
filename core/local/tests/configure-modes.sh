#!/usr/bin/env bash
# Shell harness for bin/configure's --mode and bin/db-create.sh's first .env:
# what ./.env and src/api/.env come out as in development and production mode,
# and that switching back restores the development values without touching
# APP_KEY or any other line. It works on a throwaway copy of the scripts with a
# fake docker, so it never reaches the real stack. Run it in a container (it
# needs bash, awk and openssl, which the PHP image has):
#
#   docker run --rm --entrypoint bash -v "$PWD":/src:ro deployme-php:local \
#       /src/core/local/tests/configure-modes.sh
set -euo pipefail

src=${1:-/src}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

repo=$work/repo
mkdir -p "$repo/src/api" "$work/bin" "$work/home/code" "$work/infra/scripts"
cp -R "$src/bin" "$repo/bin"
cp "$src"/docker-compose.local.yml "$src"/compose.*.yml "$repo/"
cp "$src/src/api/.env.example" "$repo/src/api/.env.example"

# docker answers yes to everything and runs nothing; so does the shared stack's
# pg-create-db.sh.
printf '#!/bin/sh\nexit 0\n' > "$work/bin/docker"
printf '#!/bin/sh\nexit 0\n' > "$work/infra/scripts/pg-create-db.sh"
echo 'POSTGRES_USER=postgres' > "$work/infra/.env"
chmod +x "$work/bin/docker" "$work/infra/scripts/pg-create-db.sh"
export PATH="$work/bin:$PATH" HOME=$work/home USER=tester INFRA_DIR=$work/infra

failures=0
pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; failures=$((failures + 1)); }
# expect <label> <actual> <expected>
expect() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1: got '$2', wanted '$3'"; fi; }
value() { { grep -E "^$1=" "$2" || true; } | tail -n 1 | cut -d= -f2-; }
# Quiet unless it fails (the warnings about this fake machine's ssh are noise).
quiet() { if ! (cd "$repo" && "$@" </dev/null >"$work/out" 2>&1); then cat "$work/out"; return 1; fi; }
configure() { quiet bin/configure --stack shared --roots "$HOME/code" "$@"; }
db_create() { quiet bin/db-create.sh; }
# Everything but the three keys the mode owns.
others() { grep -vE '^(APP_ENV|APP_DEBUG|LOG_LEVEL)=' "$1"; }

root_env=$repo/.env
app_env=$repo/src/api/.env

echo "A first run without --mode (nobody to ask) is development"
configure
expect "DEPLOYME_MODE" "$(value DEPLOYME_MODE "$root_env")" dev
expect "COMPOSE_FILE" "$(value COMPOSE_FILE "$root_env")" docker-compose.local.yml:compose.host.yml
[ ! -e "$app_env" ] && pass "src/api/.env isn't created by configure" || fail "configure created src/api/.env"

echo "db-create in development mode copies .env.example as it is"
db_create
expect "APP_ENV" "$(value APP_ENV "$app_env")" local
expect "APP_DEBUG" "$(value APP_DEBUG "$app_env")" true
expect "APP_KEY is left to make setup" "$(value APP_KEY "$app_env")" ""
diff <(grep -v '^DB_PASSWORD=' "$app_env") <(grep -v '^DB_PASSWORD=' "$repo/src/api/.env.example") >/dev/null \
    && pass "the rest is .env.example's" || fail "db-create changed more than DB_PASSWORD in dev mode"

# A real app env from here on: a key, and a line of the user's own.
key='base64:c2VjcmV0LWtleS10aGF0LW11c3Qtc3VydmktdmUtYWxsIQ=='
sed -i "s#^APP_KEY=.*#APP_KEY=$key#" "$app_env"
echo 'MY_OWN_SETTING="kept as is"' >> "$app_env"
cp "$app_env" "$work/dev.env"
inode=$(ls -i "$app_env" | awk '{print $1}')

echo "--mode prod"
configure --mode prod
expect "DEPLOYME_MODE" "$(value DEPLOYME_MODE "$root_env")" prod
expect "COMPOSE_FILE adds compose.prod.yml before compose.host.yml" "$(value COMPOSE_FILE "$root_env")" docker-compose.local.yml:compose.prod.yml:compose.host.yml
expect "APP_ENV" "$(value APP_ENV "$app_env")" production
expect "APP_DEBUG" "$(value APP_DEBUG "$app_env")" false
expect "LOG_LEVEL" "$(value LOG_LEVEL "$app_env")" warning
expect "APP_KEY untouched" "$(value APP_KEY "$app_env")" "$key"
diff <(others "$app_env") <(others "$work/dev.env") >/dev/null && pass "every other line untouched" || fail "other lines changed"
expect "written in place (same inode, for the single-file mount)" "$(ls -i "$app_env" | awk '{print $1}')" "$inode"

echo "Running it again without --mode keeps prod"
configure
expect "DEPLOYME_MODE" "$(value DEPLOYME_MODE "$root_env")" prod
expect "APP_DEBUG" "$(value APP_DEBUG "$app_env")" false

echo "--mode dev switches back"
configure --mode dev
expect "DEPLOYME_MODE" "$(value DEPLOYME_MODE "$root_env")" dev
expect "COMPOSE_FILE" "$(value COMPOSE_FILE "$root_env")" docker-compose.local.yml:compose.host.yml
expect "APP_ENV" "$(value APP_ENV "$app_env")" local
expect "APP_DEBUG" "$(value APP_DEBUG "$app_env")" true
expect "LOG_LEVEL" "$(value LOG_LEVEL "$app_env")" debug
expect "APP_KEY untouched" "$(value APP_KEY "$app_env")" "$key"
cmp -s "$app_env" "$work/dev.env" && pass "src/api/.env is byte for byte what it was before prod" || fail "src/api/.env differs from before prod"

echo "A dev machine's own LOG_LEVEL survives configure in dev"
sed -i 's/^LOG_LEVEL=.*/LOG_LEVEL=info/' "$app_env"
configure
expect "LOG_LEVEL" "$(value LOG_LEVEL "$app_env")" info

echo "--mode production and the standalone stack"
quiet bin/configure --stack standalone --roots "$HOME/code" --mode production
expect "DEPLOYME_MODE" "$(value DEPLOYME_MODE "$root_env")" prod
expect "COMPOSE_FILE" "$(value COMPOSE_FILE "$root_env")" docker-compose.local.yml:compose.standalone.yml:compose.prod.yml:compose.host.yml

echo "--mode nonsense is refused and changes nothing"
cp "$root_env" "$work/root.env"
if (cd "$repo" && bin/configure --mode staging </dev/null >/dev/null 2>&1); then fail "--mode staging was accepted"; else pass "--mode staging is refused"; fi
cmp -s "$root_env" "$work/root.env" && pass "./.env unchanged" || fail "./.env changed"

echo "db-create's first .env in production mode"
rm -f "$app_env"
configure --mode prod
db_create
expect "APP_ENV" "$(value APP_ENV "$app_env")" production
expect "APP_DEBUG" "$(value APP_DEBUG "$app_env")" false
expect "LOG_LEVEL" "$(value LOG_LEVEL "$app_env")" warning
case "$(value APP_KEY "$app_env")" in
    base64:?*) [ "$(value APP_KEY "$app_env" | cut -d: -f2 | base64 -d | wc -c)" -eq 32 ] && pass "APP_KEY generated (32 bytes)" || fail "APP_KEY isn't 32 bytes" ;;
    *) fail "APP_KEY wasn't generated" ;;
esac
[ -n "$(value REVERB_APP_KEY "$app_env")" ] && [ -n "$(value REVERB_APP_SECRET "$app_env")" ] \
    && pass "Reverb credentials generated" || fail "Reverb credentials missing"
id=$(value REVERB_APP_ID "$app_env")
[ "$id" -ge 100000 ] && [ "$id" -le 999999 ] && pass "REVERB_APP_ID is six digits" || fail "REVERB_APP_ID is $id"
generated=$(value APP_KEY "$app_env")
db_create
expect "a second db-create keeps that APP_KEY" "$(value APP_KEY "$app_env")" "$generated"

echo
if [ "$failures" -eq 0 ]; then echo "All checks passed."; else echo "$failures check(s) failed."; exit 1; fi
