# deploy.me — local PHP image: FrankenPHP (PHP 8.5) serving Laravel in classic
# (non-worker) mode. The application is bind-mounted from ./src/api at runtime;
# this image is only the runtime. Build context is the repository root.
#
# The heavy part (extensions, tools) is Dockerfile.base, pulled ready-made from
# Docker Hub. This file only adds the config files from this checkout and this
# machine's user, so it builds in seconds. `make build` (bin/build) pulls the
# base, or builds it from source when it can't, or when asked to:
#
#   make build                         # pull the base, add the rest
#   bin/configure --build-from-source  # always compile the base here

ARG DEPLOYME_PHP_BASE=romanovich0195/deployme-php:1
FROM ${DEPLOYME_PHP_BASE}

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
