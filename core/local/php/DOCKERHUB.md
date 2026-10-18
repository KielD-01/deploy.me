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
| **Tags** | `1.1.0`, `1.1`, `1`, `latest` | `1.1.0-slim`, `1.1-slim`, `1-slim`, `slim` |
| **Base** | FrankenPHP 1 with PHP 8.5 | the official `php:8.5-cli` (Debian) |
| **Database / queues** | PostgreSQL 18 and Valkey, beside it | SQLite, and the database for queues and cache |
| **Size** | ≈1.06 GB on disk, ≈258 MB to pull | ≈0.97 GB on disk, ≈235 MB to pull |

## Tags

Semantic versions. Each build gets a version of its own, and the shorter tags move to the
newest build in their line:

| Tag (full) | Tag (slim) | What it is |
|---|---|---|
| `1.1.3` | `1.1.3-slim` | One build, never pushed again. Pin this to never change under you |
| `1.1` | `1.1-slim` | The newest build of 1.1: the same contents, with the latest security fixes |
| `1` | `1-slim` | The newest build of 1.x. What deploy.me installs use |
| `latest` | `slim` | The same image as the newest `1` / `1-slim` |
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

### full (`1`, `latest`)

- **[FrankenPHP](https://frankenphp.dev) 1 with PHP 8.5** (`dunglas/frankenphp:1-php8.5`)
- **PHP extensions:**
  - `pcntl`, for queue worker signals;
  - `pdo_pgsql` and `pgsql`, for PostgreSQL;
  - `redis`, for Valkey or Redis;
  - `intl`, `zip`, `bcmath` and `opcache`;
  - `sockets` and `uv`, for Reverb websockets beyond 1,024 connections.
- **Tools:**
  - `openssh-client`, `rsync`, `git`, `tar` and `zstd`, for servers, uploads and repositories;
  - `supervisor`, for the queue workers and the scheduler;
  - `postgresql-client`, `procps`, `jq`, `curl` and `unzip`;
  - Composer 2.
- `TZ=UTC` by default. deploy.me sets your timezone at runtime.

### slim (`1-slim`, `slim`)

- **PHP 8.5 CLI** (the official `php:8.5-cli`, Debian). deploy.me serves HTTP with PHP's
  built-in server (four workers) behind a plain Caddy.
- **PHP extensions:** `pcntl`, `pdo_sqlite`, `intl`, `zip`, `bcmath`, `opcache`, `sockets`
  and `uv`. No `pgsql` and no `redis`.
- **Tools:** the same as full, with `sqlite3` in place of `postgresql-client`.

There's no application code and no entrypoint. The image runs nothing on its own until
deploy.me's own `Dockerfile` adds them.

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
FROM romanovich0195/deployme-php:1        # follows every 1.x build
# FROM romanovich0195/deployme-php:1-slim # the slim flavour
# FROM romanovich0195/deployme-php:1.1.0  # or one build, exactly
```

Prefer to compile it? Run `bin/configure --build-from-source && make build` in deploy.me,
or build it directly:

```bash
docker build -f core/local/php/Dockerfile.base -t deployme-php-base:local .
docker build -f core/local/php/Dockerfile.base-slim -t deployme-php-base:local-slim .
```

## Source and license

- Dockerfiles: [`core/local/php/Dockerfile.base`](https://github.com/KielD-01/deploy.me/blob/main/core/local/php/Dockerfile.base) and [`Dockerfile.base-slim`](https://github.com/KielD-01/deploy.me/blob/main/core/local/php/Dockerfile.base-slim)
- Built by: [`.github/workflows/php-image.yml`](https://github.com/KielD-01/deploy.me/blob/main/.github/workflows/php-image.yml) on GitHub Actions, with provenance attestations
- License: [MIT](https://github.com/KielD-01/deploy.me/blob/main/LICENSE). Made together with Claude ❤️
