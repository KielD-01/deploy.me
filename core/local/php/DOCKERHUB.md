# deployme-php

The PHP runtime of **[deploy.me](https://github.com/KielD-01/deploy.me)**: a local,
Dockerized admin app that deploys your projects to your own servers over SSH, with
releases, Docker Compose, blue/green, rollbacks and live logs.

This is the heavy part of its image, built ready-made so an install pulls it instead of
compiling PHP extensions. On a fast Mac that takes a minute; elsewhere it takes several.
You normally don't pull it yourself: deploy.me's `bin/install` and `make build` do, and add
the app's config files and your user on top in about a second.

It comes in two flavours, one per deploy.me install flavour (`bin/configure --flavour`):

| | **full** (the default) | **slim** |
|---|---|---|
| **Tags** | `2.0.0`, `2.0`, `2`, `latest` | `2.0.0-slim`, `2.0-slim`, `2-slim`, `slim` |
| **Base** | FrankenPHP 1 with PHP 8.5 | the official `php:8.5-cli` (Debian) |
| **Database / queues** | PostgreSQL 18 and Valkey, beside it | SQLite, and the database for queues and cache |
| **Size** | ≈0.46 GB on disk, ≈107 MB to pull | ≈0.38 GB on disk, ≈87 MB to pull |

## Tags

Semantic versions. Each build gets a version of its own, and the shorter tags move to the
newest build in their line:

| Tag (full) | Tag (slim) | What it is |
|---|---|---|
| `2.0.3` | `2.0.3-slim` | One build, never pushed again. Pin this to never change under you |
| `2.0` | `2.0-slim` | The newest build of 2.0: the same contents, with the latest security fixes |
| `2` | `2-slim` | The newest build of 2.x. What deploy.me installs use |
| `latest` | `slim` | The same image as the newest `2` / `2-slim` |
| `sha-<commit>` | `sha-<commit>-slim` | One build, by the deploy.me commit it came from |

Both flavours are built from the same commit and get the same version number.

- **MINOR** goes up when the image adds or changes something (a tool, an extension).
- **MAJOR** goes up when an image built on it could break (another PHP major, an extension gone).
- **PATCH** is every rebuild in between.

Every tag is multi-arch: **linux/amd64** and **linux/arm64** (Apple silicon, Graviton,
Raspberry Pi 4/5). Images are rebuilt when `Dockerfile.base` or `Dockerfile.base-slim`
changes and every Monday, to pick up PHP, FrankenPHP and Debian security fixes. Each one is
labelled with its version and commit (`org.opencontainers.image.version`, `.revision`).

## What's inside

### full (`2`, `latest`)

- **[FrankenPHP](https://frankenphp.dev) 1 with PHP 8.5** (`dunglas/frankenphp:1-php8.5`)
- **PHP extensions:**
  - `pcntl`, for queue worker signals;
  - `pdo_pgsql` and `pgsql`, for PostgreSQL;
  - `redis`, for Valkey or Redis;
  - `zip`, `bcmath` and `opcache`;
  - `sockets` and `uv`, for Reverb websockets beyond 1,024 connections.
- **Tools:**
  - `openssh-client`, `rsync`, `git`, `tar` and `zstd`, for servers, uploads and repositories;
  - `procps`, `jq`, `curl` and `unzip`;
  - Composer 2.
- `TZ=UTC` by default. deploy.me sets your timezone at runtime.

### slim (`2-slim`, `slim`)

- **PHP 8.5 CLI** (the official `php:8.5-cli`, Debian). deploy.me serves HTTP with PHP's
  built-in server (four workers) behind a plain Caddy.
- **PHP extensions:** `pcntl`, `pdo_sqlite`, `zip`, `bcmath`, `opcache`, `sockets` and `uv`.
  No `pgsql` and no `redis`.
- **Tools:** the same as full, and `sqlite3`.

There's no application code and no entrypoint. The image runs nothing on its own until
deploy.me's own `Dockerfile` adds them, along with the small PHP supervisor that runs the
queue workers and the scheduler.

### What's left out (since 2.0)

- **The compiler toolchain.** The official PHP images keep gcc, g++, binutils, make,
  autoconf and the C headers (`$PHPIZE_DEPS`) so you can compile extensions on top: about
  250 MB that nothing runs. These images compile theirs, purge all of that, and are
  flattened into a single layer, so the purged files aren't still in a lower one. The PHP
  headers and source tarball go too: **you can't compile another extension on top**
  (`docker-php-ext-install`, `pecl`, `install-php-extensions` won't work). Build from the
  Dockerfiles below if you need one.
- **`intl`** (and ICU): deploy.me doesn't use it; libraries that can fall back to Symfony's
  polyfills.
- **`postgresql-client`**: deploy.me reaches Postgres through PHP, and runs `psql` and
  `pg_dump` in the database's own container.
- **`supervisor`** and Python: deploy.me's `Dockerfile` adds `deployme-supervise`, a PHP
  script that reads the same program files.
- **`php-cgi` and `phpdbg`**: deploy.me runs the `php` CLI, and full's FrankenPHP embeds PHP.

That's why this is 2.0: an image built on 1.x (one that runs `supervisord`, or needs `intl`)
breaks on it. **The `1.x` tags (`1`, `1.1`, `1-slim`, `1.1-slim`…) stay on Docker Hub as they are**, for
deploy.me checkouts from before 2.0, which pull `1` / `1-slim`. They're no longer rebuilt.

The environment the upstream image sets is declared again (`PATH`, `PHP_INI_DIR`, and for
full `XDG_CONFIG_HOME`, `XDG_DATA_HOME`, `GODEBUG`, its `WORKDIR`, ports and health check),
except the variables that only described PHP's source tarball. `PHP_VERSION` is the line,
`8.5`; `php -v` has the exact release.

## Use

```bash
# macOS, Linux, WSL2
curl -fsSL https://raw.githubusercontent.com/KielD-01/deploy.me/main/install.sh | bash
```

```powershell
# Windows (PowerShell)
irm https://raw.githubusercontent.com/KielD-01/deploy.me/main/bin/install.ps1 | iex
```

Building on top of it yourself:

```dockerfile
FROM romanovich0195/deployme-php:2        # follows every 2.x build
# FROM romanovich0195/deployme-php:2-slim # the slim flavour
# FROM romanovich0195/deployme-php:2.0.0  # or one build, exactly
```

Prefer to compile it? Run `bin/configure --build-from-source && make build` in deploy.me,
or build it directly:

```bash
docker build -f core/local/php/Dockerfile.base -t deployme-php-base:local .
docker build -f core/local/php/Dockerfile.base-slim -t deployme-php-base:local-slim .
# BuildKit (the default builder) is needed: the build mounts core/local/php/trim-base.sh.
```

## Source and license

- Dockerfiles: [`core/local/php/Dockerfile.base`](https://github.com/KielD-01/deploy.me/blob/main/core/local/php/Dockerfile.base) and [`Dockerfile.base-slim`](https://github.com/KielD-01/deploy.me/blob/main/core/local/php/Dockerfile.base-slim)
- Built by: [`.github/workflows/php-image.yml`](https://github.com/KielD-01/deploy.me/blob/main/.github/workflows/php-image.yml) on GitHub Actions, with provenance attestations
- License: [MIT](https://github.com/KielD-01/deploy.me/blob/main/LICENSE). Made together with Claude ❤️
