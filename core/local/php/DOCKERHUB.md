# deployme-php

The PHP runtime of **[deploy.me](https://github.com/KielD-01/deploy.me)**: a local,
Dockerized admin app that deploys your projects to your own servers over SSH, with
releases, Docker Compose, blue/green, rollbacks and live logs.

This is the heavy part of its image, built ready-made so an install pulls it instead of
compiling PHP extensions. On a fast Mac that takes a minute; elsewhere it takes several.
You normally don't pull it yourself: deploy.me's `bin/install` and `make build` do, and add
the app's config files and your user on top in about a second.

## Tags

Semantic versions. Each build gets a version of its own, and the shorter tags move to the
newest build in their line:

| Tag | What it is |
|---|---|
| `1.0.3` | One build, never pushed again. Pin this to never change under you |
| `1.0` | The newest build of 1.0: the same contents, with the latest security fixes |
| `1` | The newest build of 1.x. What deploy.me installs use |
| `latest` | The same image as the newest `1` |
| `sha-<commit>` | One build, by the deploy.me commit it came from |

- **MINOR** goes up when the image adds or changes something (a tool, an extension).
- **MAJOR** goes up when an image built on it could break (another PHP major, an extension gone).
- **PATCH** is every rebuild in between.

Every tag is multi-arch: **linux/amd64** and **linux/arm64** (Apple silicon, Graviton,
Raspberry Pi 4/5). Images are rebuilt when `Dockerfile.base` changes and every Monday, to
pick up FrankenPHP and Debian security fixes. Each one is labelled with its version and
commit (`org.opencontainers.image.version`, `.revision`).

## What's inside

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
# FROM romanovich0195/deployme-php:1.0.0  # or one build, exactly
```

Prefer to compile it? Run `bin/configure --build-from-source && make build` in deploy.me,
or build it directly:

```bash
docker build -f core/local/php/Dockerfile.base -t deployme-php-base:local .
```

## Source and license

- Dockerfile: [`core/local/php/Dockerfile.base`](https://github.com/KielD-01/deploy.me/blob/main/core/local/php/Dockerfile.base)
- Built by: [`.github/workflows/php-image.yml`](https://github.com/KielD-01/deploy.me/blob/main/.github/workflows/php-image.yml) on GitHub Actions, with provenance attestations
- License: [MIT](https://github.com/KielD-01/deploy.me/blob/main/LICENSE). Made together with Claude ❤️
