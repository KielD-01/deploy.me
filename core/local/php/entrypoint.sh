#!/bin/sh
# deploy.me entrypoint, shared by deployme-web, deployme-worker and deployme-reverb.
set -eu

# Per-container, memory-only runtime state: compiled ssh config, known_hosts,
# ControlPath sockets, generated gitconfig, supervisor socket.
install -d -m 0700 /run/deployme /run/deployme/ssh /run/deployme/ssh/cm /run/deployme/git

# Docker Desktop and OrbStack forward the host ssh-agent at this path. Drop the
# variable when the socket isn't there so ssh doesn't keep trying it.
if [ -n "${SSH_AUTH_SOCK:-}" ] && [ ! -S "$SSH_AUTH_SOCK" ]; then
  unset SSH_AUTH_SOCK
fi

cd /app

# Only the web container installs PHP dependencies; the others wait for vendor/
# so three containers never race composer into the same bind mount.
if [ "${DEPLOYME_ROLE:-}" = "web" ]; then
  if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
  fi
else
  i=0
  while [ ! -f vendor/autoload.php ]; do
    i=$((i + 1))
    if [ "$i" -gt 300 ]; then
      echo "vendor/autoload.php is still missing after 300 s" >&2
      exit 1
    fi
    sleep 1
  done
fi

if [ "${DEPLOYME_MODE:-dev}" = prod ]; then
  # Production: the code is the image's, so cache what reads the runtime .env
  # now. Each container has its own bootstrap/cache; the compiled views live in
  # the shared storage/, so only the web container builds them. A cache that
  # fails leaves the app slower, not down: make doctor says which is missing.
  mkdir -p /tmp/opcache
  if [ "${DEPLOYME_ROLE:-}" = "web" ]; then
    php artisan optimize --no-interaction || echo "php artisan optimize failed; the app runs uncached" >&2
  else
    for cache in config:cache event:cache route:cache; do
      php artisan "$cache" --no-interaction || echo "php artisan $cache failed; the app runs without it" >&2
    done
  fi
else
  # Development: caches written by another container (or the host) would pin
  # stale config.
  rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php
fi

# The schema follows the code: the web container applies pending migrations when
# it starts, so none waits for someone to run `make migrate`. A database that
# isn't reachable yet doesn't stop the container.
if [ "${DEPLOYME_ROLE:-}" = "web" ]; then
  # The slim flavour's database is a file in storage/ (bin/db-create.sh makes
  # it too): an empty one is a database SQLite is happy to migrate.
  if [ "${DEPLOYME_FLAVOUR:-full}" = slim ] && [ ! -e storage/database/deployme.sqlite ]; then
    mkdir -p storage/database && touch storage/database/deployme.sqlite
  fi
  php artisan migrate --force --no-interaction || echo "Pending migrations didn't run; try: make migrate" >&2
fi

exec "$@"
