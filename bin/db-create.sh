#!/usr/bin/env bash
# Create deploy.me's role and databases on the shared Postgres (idempotent).
#
#   bin/db-create.sh
#
# Generates DB_PASSWORD into src/api/.env when it's empty, then delegates to
# ~/Personal/infra/scripts/pg-create-db.sh and adds what that script doesn't:
# a connection limit (the shared server has ~97 usable connections) and the
# deployme_test database for the test suite.
set -euo pipefail
cd "$(dirname "$0")/.."

INFRA="${INFRA_DIR:-$HOME/Personal/infra}"
ENV_FILE=src/api/.env

[ -f "$ENV_FILE" ] || cp src/api/.env.example "$ENV_FILE"

pass=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2- | tr -d '"' || true)
if [ -z "$pass" ]; then
  pass=$(openssl rand -hex 24)
  sed -i.bak "s/^DB_PASSWORD=.*/DB_PASSWORD=${pass}/" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
fi

"$INFRA/scripts/pg-create-db.sh" deployme "$pass" >/dev/null

set -a; . "$INFRA/.env"; set +a
SU="${POSTGRES_USER:-postgres}"

docker exec -i postgres psql -v ON_ERROR_STOP=1 -U "$SU" -d postgres <<'SQL'
ALTER ROLE deployme CONNECTION LIMIT 12 CREATEDB;
ALTER ROLE deployme SET idle_in_transaction_session_timeout = '60s';
SELECT 'CREATE DATABASE deployme_test OWNER deployme'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'deployme_test')\gexec
SQL

echo "Postgres ready: deployme and deployme_test (role deployme, connection limit 12)."
