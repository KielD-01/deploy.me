# deploy.me — local PHP image: FrankenPHP (PHP 8.5) serving Laravel in classic
# (non-worker) mode. Build context is the repository root. Two targets:
#
#   runtime   development mode (the default): only the runtime; the application
#             is bind-mounted from ./src/api, so code changes show up live.
#   app       production mode (bin/configure --mode prod): runtime plus the
#             application itself, its --no-dev dependencies and built assets.
#             A code change needs `make build && make up`.
#
# The heavy part (extensions, tools) is Dockerfile.base, pulled ready-made from
# Docker Hub. This file only adds the config files from this checkout and this
# machine's user, so it builds in seconds. `make build` (bin/build) pulls the
# base, or builds it from source when it can't, or when asked to:
#
#   make build                         # pull the base, add the rest
#   bin/configure --build-from-source  # always compile the base here

ARG DEPLOYME_PHP_BASE=romanovich0195/deployme-php:1
FROM ${DEPLOYME_PHP_BASE} AS runtime

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

# --- Production mode ----------------------------------------------------------
# The application baked in. Nothing of src/api/storage or src/api/.env is in the
# image (.dockerignore): compose mounts both at runtime. bootstrap/cache holds
# only Composer's package manifest; the entrypoint caches config, routes, views
# and events at start, since those need the runtime .env.

# PHP dependencies, without the dev ones. The lock file first, so a code change
# doesn't download them again.
FROM runtime AS vendor
WORKDIR /app
COPY src/api/composer.json src/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist \
    && rm -rf /root/.composer/cache /root/.cache/composer
COPY src/api/ ./
RUN install -d bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && rm -rf /root/.composer/cache /root/.cache/composer

# The frontend, built here so the final image has no node. Tailwind also reads
# the pagination views from vendor/ (@source in resources/css/app.css).
FROM node:24-alpine AS assets
WORKDIR /app
COPY src/api/package.json src/api/package-lock.json src/api/.npmrc ./
RUN npm ci --no-audit --no-fund
COPY src/api/vite.config.js ./
COPY src/api/resources ./resources
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN mkdir -p public storage/framework/views && npm run build

FROM vendor AS app
COPY --from=assets /app/public/build ./public/build
COPY core/local/php/php-prod.ini /usr/local/etc/php/conf.d/zz-mode.ini
# The code stays root's; only the caches the entrypoint writes (and storage,
# which compose replaces with ./src/api/storage) belong to the container user.
ARG DEPLOYME_UID=0
ARG DEPLOYME_GID=0
RUN chown -R "$DEPLOYME_UID:$DEPLOYME_GID" bootstrap/cache storage
ENV DEPLOYME_MODE=prod
