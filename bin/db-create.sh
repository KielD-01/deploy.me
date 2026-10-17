#!/usr/bin/env bash
# Create deploy.me's role and databases (idempotent), on the shared Postgres or
# on deploy.me's own one, whichever ./.env names (bin/configure).
#
#   bin/db-create.sh
#
# Generates DB_PASSWORD into src/api/.env when it's empty. On the shared stack
# it delegates to ~/Personal/infra/scripts/pg-create-db.sh; on its own Postgres
# it starts that container and creates the role itself. Either way it adds a
# connection limit (a shared server has ~97 usable connections) and the
# deployme_test database for the test suite.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
cd "$DEPLOYME_ROOT"

ENV_FILE=src/api/.env
[ -f "$ENV_FILE" ] || cp src/api/.env.example "$ENV_FILE"

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
