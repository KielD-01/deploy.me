# deployme-php

The PHP runtime of **[deploy.me](https://github.com/KielD-01/deploy.me)**: a local,
Dockerized admin app that deploys your projects to your own servers over SSH, with
releases, Docker Compose, blue/green, rollbacks and live logs.

This is the heavy part of its image, built ready-made so an install pulls it instead of
compiling PHP extensions. On a fast Mac that takes a minute; elsewhere it takes several.
You normally don't pull it yourself: deploy.me's `bin/install` and `make build` do, and add
the app's config files and your user on top in about a second.

## Tags

| Tag | What it is |
|---|---|
| `1` | What deploy.me installs use. Moves with every rebuild of this line |
| `latest` | The same image as `1` |
| `sha-<commit>` | One build, pinned to the deploy.me commit it came from |

Every tag is multi-arch: **linux/amd64** and **linux/arm64** (Apple silicon, Graviton,
Raspberry Pi 4/5). Images are rebuilt when `Dockerfile.base` changes and every Monday, to
pick up FrankenPHP and Debian security fixes.

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
git clone https://github.com/KielD-01/deploy.me.git
cd deploy.me
bin/install
```

Building on top of it yourself:

```dockerfile
FROM romanovich0195/deployme-php:1
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
