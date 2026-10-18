#!/usr/bin/env bash
# Create deploy.me's role and databases (idempotent), on the shared Postgres or
# on deploy.me's own one, whichever ./.env names (bin/configure). In the slim
# flavour, the SQLite file instead (src/api/storage/database/deployme.sqlite).
#
#   bin/db-create.sh
#
# Generates DB_PASSWORD into src/api/.env when it's empty (and, in production
# mode, APP_KEY and the Reverb credentials, which the containers can't write
# there). On the shared stack it delegates to
# ~/Personal/infra/scripts/pg-create-db.sh; on its own Postgres
# it starts that container and creates the role itself. Either way it adds a
# connection limit (a shared server has ~97 usable connections) and the
# deployme_test database for the test suite.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
cd "$DEPLOYME_ROOT"

ENV_FILE=src/api/.env
if [ ! -f "$ENV_FILE" ]; then
    cp src/api/.env.example "$ENV_FILE"
    # .env.example is development's (local, debug on); production mode starts
    # from production's.
    [ "$(deployme_mode)" = prod ] && deployme_app_env_for_mode prod "$ENV_FILE"
    # .env.example is the full flavour's (PostgreSQL, Valkey).
    [ "$(deployme_flavour)" = slim ] && deployme_app_env_for_flavour slim "$ENV_FILE"
fi

# In production mode the containers mount .env read-only, so `make setup` can't
# write the keys it generates there: they're made here instead, only when blank.
if [ "$(deployme_mode)" = prod ]; then
    if [ -z "$(deployme_env APP_KEY "$ENV_FILE")" ]; then
        key="base64:$(openssl rand -base64 32)"
        sed -i.bak "s#^APP_KEY=.*#APP_KEY=${key}#" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
        deployme_ok "Generated APP_KEY in $ENV_FILE: back it up (make backup)"
    fi
    if [ -z "$(deployme_env REVERB_APP_KEY "$ENV_FILE")" ]; then
        sed -i.bak -e "s/^REVERB_APP_ID=.*/REVERB_APP_ID=$((100000 + $(od -An -N2 -tu2 /dev/urandom | tr -d ' ') % 900000))/" \
            -e "s/^REVERB_APP_KEY=.*/REVERB_APP_KEY=$(openssl rand -hex 10)/" \
            -e "s/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=$(openssl rand -hex 20)/" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
        deployme_ok "Generated the Reverb credentials in $ENV_FILE"
    fi
fi

# Slim: one file in storage/, which every container mounts. No role, no
# password, no server; the tests run on an in-memory SQLite (make test).
if [ "$(deployme_flavour)" = slim ]; then
    mkdir -p "$(dirname "$DEPLOYME_SQLITE_FILE")"
    [ -e "$DEPLOYME_SQLITE_FILE" ] || : > "$DEPLOYME_SQLITE_FILE"
    echo "SQLite ready: $DEPLOYME_SQLITE_FILE (the web container migrates it when it starts)."
    exit 0
fi

pass=$(deployme_env DB_PASSWORD "$ENV_FILE")
if [ -z "$pass" ]; then
    pass=$(openssl rand -hex 24)
    sed -i.bak "s/^DB_PASSWORD=.*/DB_PASSWORD=${pass}/" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
fi

if [ "$(deployme_stack)" = standalone ]; then
    deployme_compose up -d --wait deployme-postgres >/dev/null
    SU=postgres
    # The password is hex, so it's safe inside the quoted literal.
    docker exec -i deployme-postgres psql -v ON_ERROR_STOP=1 -U "$SU" -d postgres <<SQL
SELECT 'CREATE ROLE deployme LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'deployme')\gexec
ALTER ROLE deployme WITH LOGIN PASSWORD '${pass}';
SELECT 'CREATE DATABASE deployme OWNER deployme' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'deployme')\gexec
SQL
else
    INFRA="${INFRA_DIR:-$HOME/Personal/infra}"
    [ -x "$INFRA/scripts/pg-create-db.sh" ] || deployme_die "No shared stack at $INFRA. Run bin/configure --stack standalone to use deploy.me's own Postgres."
    "$INFRA/scripts/pg-create-db.sh" deployme "$pass" >/dev/null
    set -a; . "$INFRA/.env"; set +a
    SU="${POSTGRES_USER:-postgres}"
fi

docker exec -i "$(deployme_pg_container)" psql -v ON_ERROR_STOP=1 -U "$SU" -d postgres <<'SQL'
ALTER ROLE deployme CONNECTION LIMIT 12 CREATEDB;
ALTER ROLE deployme SET idle_in_transaction_session_timeout = '60s';
SELECT 'CREATE DATABASE deployme_test OWNER deployme'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'deployme_test')\gexec
SQL

echo "Postgres ready: deployme and deployme_test (role deployme, connection limit 12)."
