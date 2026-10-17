# deploy.me — local PHP image: FrankenPHP (PHP 8.5) serving Laravel in classic
# (non-worker) mode, plus the CLI tools the SSH and git layer shells out to.
#
# The application is bind-mounted from ./src/api at runtime; this image is only
# the runtime. Build context is the repository root.
#
#   docker compose -f docker-compose.local.yml build

FROM dunglas/frankenphp:1-php8.5

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1

#   pcntl            queue worker signals
#   pdo_pgsql/pgsql  the shared PostgreSQL 18
#   redis            the shared Valkey (queues, cache, locks)
#   intl zip bcmath  framework basics
#   opcache
#   sockets + uv     Reverb. Without ext-uv ReactPHP falls back to stream_select()
#                    and silently caps at ~1024 connections, so the build fails
#                    if it is missing.
RUN install-php-extensions pcntl pdo_pgsql pgsql redis intl zip opcache bcmath sockets uv \
 && php -m | grep -qx uv \
 && php -m | grep -qx pcntl

#   openssh-client       ssh, ssh-keygen (servers, host keys)
#   rsync tar zstd       uploads
#   git                  local repos, archives, pushes
#   supervisor           queue workers + scheduler in deployme-worker
#   postgresql-client    psql for the doctor and tests
#   procps jq curl       health checks and diagnostics
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      openssh-client rsync git tar zstd supervisor curl ca-certificates tzdata \
      postgresql-client procps jq unzip \
 && ln -sf /usr/share/zoneinfo/$TZ /etc/localtime \
 && echo "$TZ" > /etc/timezone \
 && rm -rf /var/lib/apt/lists/* /etc/supervisor/conf.d/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY core/local/php/php.ini /usr/local/etc/php/conf.d/zz-deployme.ini
COPY core/local/php/supervisor/ /etc/supervisor/
# Baked in (not bind-mounted) so app code can't swap the credential helper.
COPY core/local/php/git-askpass /usr/local/bin/deployme-git-askpass
COPY core/local/php/entrypoint.sh /usr/local/bin/deployme-entrypoint
RUN chmod 0755 /usr/local/bin/deployme-git-askpass /usr/local/bin/deployme-entrypoint

# The user the containers run as. 0 (root) on macOS; on Linux and WSL2 the host
# user's ids (compose passes DEPLOYME_UID/GID from ./.env), so bind-mounted files
# keep their owner and ssh accepts the ~/.ssh/config that user owns. Its home is
# /root either way, where compose mounts ~/.ssh. FrankenPHP's /data and /config
# and PHP's session folder must be writable by it too.
ARG DEPLOYME_UID=0
ARG DEPLOYME_GID=0
RUN if [ "$DEPLOYME_UID" != 0 ]; then \
      getent group "$DEPLOYME_GID" >/dev/null || groupadd -g "$DEPLOYME_GID" deployme; \
      useradd -o -u "$DEPLOYME_UID" -g "$DEPLOYME_GID" -d /root -M -s /bin/bash deployme; \
      chown -R "$DEPLOYME_UID:$DEPLOYME_GID" /root /data /config; \
    fi

WORKDIR /app

# Documentation only — nothing is published; the edge reaches these by name.
EXPOSE 8000 8080

ENTRYPOINT ["deployme-entrypoint"]
