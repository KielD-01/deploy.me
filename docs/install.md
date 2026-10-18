# 🧩 Installing deploy.me on macOS, Linux and Windows

deploy.me runs in Docker on your own machine, the same way on all three systems:

| | 🍎 macOS | 🐧 Linux | 🪟 Windows |
|---|---|---|---|
| **Docker** | Docker Desktop or OrbStack | Docker Engine + the Compose plugin (or Docker Desktop) | Docker Desktop with the WSL2 backend |
| **Where it runs** | your Mac | your machine | a WSL2 distribution (Ubuntu) |
| **Install** | `curl … install.sh \| bash` | `curl … install.sh \| bash` | `irm … install.ps1 \| iex` in PowerShell |
| **Containers run as** | root (Docker maps ownership) | your user's UID | your WSL user's UID |
| **Local CA trusted in** | the login keychain | the system store + NSS (Chrome) | WSL **and** Windows (Edge, Chrome) |
| **Nightly backup** | launchd | systemd user timer, or cron | systemd in WSL, or cron |

You end up at **https://deployme.localhost**, signing in with a passkey or a password. If
you set it up by hand rather than with `bin/install`, `make init` prints a one-time link
that creates your account (or run `make enroll`). Open the link within 15 minutes. The
first-visit "Create your account" page is off by default, because until you use it anything on
your machine could claim the account; put `DEPLOYME_WEB_SETUP=true` in `src/api/.env` to turn it on.

- [Before you start](#before-you-start)
- [macOS](#-macos)
- [Linux](#-linux)
- [Windows (WSL2)](#-windows-wsl2)
- [The PHP image](#the-php-image)
- [Two stack modes](#two-stack-modes)
- [Two flavours: full and slim](#two-flavours-full-and-slim)
- [Development and production mode](#development-and-production-mode)
- [What `bin/configure` writes](#what-binconfigure-writes)
- [Updating, stopping, uninstalling](#updating-stopping-uninstalling)
- [Troubleshooting](#troubleshooting)

## Before you start

- 🔑 **Your servers in `~/.ssh/config`.** deploy.me lists them from its `Host` entries and
  connects with their `IdentityFile` keys, or with your ssh-agent if one is running.
  ```sshconfig
  Host prod
    HostName 203.0.113.10
    User deploy
    IdentityFile ~/.ssh/id_ed25519
  ```
- 📁 **A folder for your projects.** Local projects are imported from your *project roots*,
  which are mounted into the containers at the same paths. By default these are whichever
  of `~/Personal ~/Claude ~/Projects ~/Cursor ~/code ~/src ~/dev ~/work` exist, or
  `~/Projects`. Pass `--roots ~/code:~/work` to choose them yourself.
- 🌐 **Ports 80 and 443 on 127.0.0.1** must be free. deploy.me's edge (Caddy) listens there.
  Nothing is exposed beyond your machine.

## 🍎 macOS

1. Install **[Docker Desktop](https://www.docker.com/products/docker-desktop/)** or
   **[OrbStack](https://orbstack.dev)** and start it.
2. Install git and make if you don't have them: `xcode-select --install`.
3. Then:

```bash
curl -fsSL https://raw.githubusercontent.com/KielD-01/deploy.me/main/install.sh | bash
```

To do it by hand instead, run `git clone https://github.com/KielD-01/deploy.me.git`, then
`cd deploy.me` and `bin/install`.

macOS asks for your password once, to trust the local CA in your login keychain. For
Firefox, import `.deployme/caddy-root.crt` under Settings → Privacy & Security →
Certificates, or set `security.enterprise_roots.enabled` to `true` in `about:config`.

## 🐧 Linux

Tested with Ubuntu, Debian and Fedora. Any distribution with Docker Engine works.

1. Install **Docker Engine** and the **Compose plugin**
   ([docs.docker.com/engine/install](https://docs.docker.com/engine/install/)), then let
   your user run it without sudo:
   ```bash
   sudo usermod -aG docker $USER   # then log out and back in
   ```
2. Install the tools: `sudo apt install git make openssl curl libnss3-tools` (Fedora:
   `sudo dnf install git make openssl curl nss-tools`).
3. Then:

```bash
curl -fsSL https://raw.githubusercontent.com/KielD-01/deploy.me/main/install.sh | bash
```

To pass options, put them after `bash -s --`, for example
`… | bash -s -- --stack standalone --roots ~/code`. `DEPLOYME_DIR=~/somewhere` changes where
it's cloned (default `~/deploy.me`); run it again to update.

Notes:

- **The containers run as your UID and GID.** Files deploy.me writes into `src/api` and
  into your project roots stay yours, and ssh accepts your `~/.ssh/config`. When run as
  root, ssh refuses a config owned by someone else.
- **ssh-agent.** If `SSH_AUTH_SOCK` points at a socket, it's mounted into the containers. A
  socket under `/tmp/ssh-…` changes at every login, so run `bin/configure` again after
  logging in, or rely on key files (`IdentityFile`), which always work. GNOME Keyring's
  `/run/user/<uid>/keyring/ssh` stays put.
- **Browser trust.** `bin/trust-ca` uses `sudo` for the system store
  (`update-ca-certificates`, or `trust` on Fedora/Arch) and adds the CA to `~/.pki/nssdb`
  for Chrome and Chromium. Firefox keeps its own store: import `.deployme/caddy-root.crt`
  there.
- **SELinux** (Fedora, RHEL): if containers can't read the bind mounts, run
  `sudo chcon -Rt container_file_t src/api ~/.ssh <your roots>`, or set SELinux to
  permissive for Docker.

## 🪟 Windows (WSL2)

deploy.me drives ssh, rsync and git the Linux way, so on Windows it lives in **WSL2**. Your
browsers on Windows open it as usual.

1. Install WSL with Ubuntu (admin PowerShell, once): `wsl --install -d Ubuntu`, then
   restart and open Ubuntu to create your Linux user.
2. Install **[Docker Desktop](https://www.docker.com/products/docker-desktop/)**. Under
   Settings → Resources → **WSL integration**, turn it on for Ubuntu.
3. In PowerShell (no admin needed):

```powershell
irm https://raw.githubusercontent.com/KielD-01/deploy.me/main/bin/install.ps1 | iex
```

The script:

- checks WSL2 and Docker;
- installs git, make, openssl and curl inside Ubuntu if they're missing;
- clones deploy.me into `~/deploy.me` *inside WSL*;
- runs `bin/install` there.

Prefer to do it by hand? Open Ubuntu and run the same one line as on Linux
(`curl -fsSL https://raw.githubusercontent.com/KielD-01/deploy.me/main/install.sh | bash`), or follow the [Linux](#-linux) steps. Docker is already there through Docker
Desktop.

Notes:

- **Keep the checkout inside WSL** (`~/deploy.me`), not on `C:\` (`/mnt/c/…`). The Windows
  drive is slow from Linux and loses file modes, so `bin/install` refuses to run from it.
- **Your SSH keys.** WSL has its own `~/.ssh`. If `~/.ssh/config` is missing there but
  exists in Windows (`C:\Users\<you>\.ssh`), `bin/install` offers to copy it, with the modes
  ssh requires. Change any `IdentityFile C:\…` lines to `~/.ssh/…`.
- **Projects** are imported from folders inside WSL, such as `~/Projects`. Clone them there.
- **Trust.** `bin/trust-ca` adds the CA to Ubuntu, and also to Windows' *current user*
  store through `powershell.exe`, for Edge and Chrome on Windows. Windows asks you to
  confirm.
- **Day to day,** run `make` inside Ubuntu: `wsl -e bash -lc "cd ~/deploy.me && make up"`.
- **Nightly backups** use a systemd timer if systemd is on in WSL (`[boot] systemd=true` in
  `/etc/wsl.conf`, the default on new installs), and cron otherwise. Either way, they run
  only while WSL is running.

## The PHP image

Most of the image is ready-made. The PHP extensions compiled in, ssh, git and supervisor
are published for amd64 and arm64 as
[`romanovich0195/deployme-php`](https://hub.docker.com/r/romanovich0195/deployme-php) on
Docker Hub, so `make build` (`bin/build`) pulls it and only adds this checkout's config
files and your user on top. That takes seconds, where compiling the extensions takes a
minute on a fast Mac and several elsewhere.

| Tags | Flavour | Built from |
|---|---|---|
| `1.1.0`, `1.1`, `1`, `latest` | full: FrankenPHP, PostgreSQL and Redis extensions | `core/local/php/Dockerfile.base` |
| `1.1.0-slim`, `1.1-slim`, `1-slim`, `slim` | slim: plain PHP CLI, SQLite | `core/local/php/Dockerfile.base-slim` |

Installs pull `1` or `1-slim` ([the two flavours](#two-flavours-full-and-slim)); the
`X.Y.Z` tags never change once pushed, if you want to pin one.

- **Offline, or the base can't be pulled?** `bin/build` compiles it here from
  `core/local/php/Dockerfile.base`, without being asked.
- **Prefer to build everything yourself?** Run `bin/configure --build-from-source`, then
  `make build` (it compiles `deployme-php-base:local`, or `deployme-php-base:local-slim` in
  the slim flavour). `bin/configure --pull-base` goes back to the ready-made one.
- **Updates.** GitHub Actions (`.github/workflows/php-image.yml`) rebuilds both bases when
  either Dockerfile changes, and every Monday for PHP, FrankenPHP and Debian security fixes.
  `make build` pulls the newest one.

## Two stack modes

deploy.me needs PostgreSQL, Valkey (Redis) and an HTTPS edge (the slim flavour: only the
edge). It can bring its own, or use a stack you already run:

| | `standalone` | `shared` |
|---|---|---|
| **Used when** | nothing else is there (the default on a new machine) | a `proxy-network` with `postgres` and `caddy` containers is already running |
| **Compose files** | `docker-compose.local.yml` + `compose.standalone.yml` + `compose.host.yml` | `docker-compose.local.yml` + `compose.host.yml` |
| **Postgres / Valkey / edge** | `deployme-postgres`, `deployme-valkey`, `deployme-caddy` (volumes `deployme_postgres_data`, …) | the shared stack's own containers |
| **Network** | `deployme-edge` | `proxy-network` (external) |

`bin/configure` picks one. To choose yourself, pass `bin/configure --stack standalone` or
`--stack shared`, then run `make up`. Either way the app finds the database as `postgres`
and the cache as `redis`, so nothing in `src/api/.env` changes.

## Two flavours: full and slim

Separately from the stack and the mode, deploy.me comes in two flavours. `bin/install`
asks which one (full unless you say otherwise); `bin/configure --flavour full|slim`
chooses or switches.

| | `full` (the default) | `slim` |
|---|---|---|
| **PHP image** | `romanovich0195/deployme-php:1`: FrankenPHP 1 + PHP 8.5 | `romanovich0195/deployme-php:1-slim`: the official `php:8.5-cli` |
| **Web server** | FrankenPHP | PHP's built-in server with four workers (`PHP_CLI_SERVER_WORKERS`) |
| **Database** | PostgreSQL 18 | SQLite: `src/api/storage/database/deployme.sqlite`, WAL, 10 s busy timeout |
| **Queues, cache, locks** | Valkey | the same SQLite file (`jobs`, `cache`, `cache_locks`) |
| **Queue workers** | 2 deploys, 1 sync, 4 probes, 1 default | 2 deploys, 1 sync, 2 probes, 1 default |
| **Edge (standalone)** | caddy-docker-proxy, routing by container labels | a plain `caddy:2-alpine` with a static Caddyfile (`core/local/caddy/Caddyfile`), no Docker socket |
| **Containers (standalone)** | web, worker, reverb, postgres, valkey, caddy | web, worker, reverb, caddy |
| **Whole stack on disk** | ≈1.6 GB | ≈1.1 GB |
| **Compose files** | as in the tables above | `compose.slim.yml` added (after `compose.prod.yml`); standalone uses `compose.slim-edge.yml` in place of `compose.standalone.yml` |
| **Backups** (`make backup`) | `deployme-<stamp>.sql.gz` (pg_dump) | `deployme-<stamp>.sqlite.gz` (sqlite3 `.backup`) |
| **`make test`** | PostgreSQL (`deployme_test`) | an in-memory SQLite |

**Which one?** Full is the recommended flavour, and the one for many servers or several
deploys at once. Slim is lighter to install and run (no PostgreSQL or Valkey to keep going),
but SQLite has one writer at a time: deploys, probes, health checks and the pages all take
turns at it. That's fine for a handful of servers; with dozens, or with many deploys running
together, use full. The slim PHP image itself is only a little smaller (≈0.97 GB against
≈1.06 GB on disk): the saving is in what runs beside it.

Slim works with either mode (dev or prod) and either stack. On the **standalone** stack it
brings only its minimal Caddy (published on 127.0.0.1:80 and :443, as `deployme-caddy`, with
its CA in the same `deployme_caddy_data` volume, so `bin/trust-ca` works the same). On the
**shared** stack it uses the shared edge through the labels `deployme-web` already carries,
and simply leaves the shared PostgreSQL and Valkey alone.

```bash
bin/install --flavour slim                                   # a new install
bin/configure --flavour slim                                 # an existing one, then:
docker compose down --remove-orphans && make db-create build up
bin/configure --flavour full && make db-create build up      # and back
```

A switch starts on the other flavour's database, empty the first time: nothing is copied
across, and the old one stays where it was (the Postgres database, or the SQLite file). The
app's own settings follow the flavour: `bin/configure` sets `DB_CONNECTION`, `DB_DATABASE`,
`CACHE_STORE` and `DEPLOYME_QUEUE_DRIVER` in `src/api/.env` and leaves every other line, `APP_KEY`
above all, as it is. The queue connections keep their names (`redis`, `redis-deploys`,
`redis-probes`) in both flavours; `DEPLOYME_QUEUE_DRIVER=database` only changes what runs them.

The slim flavour's settings in `src/api/.env`:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/app/storage/database/deployme.sqlite
CACHE_STORE=database
DEPLOYME_QUEUE_DRIVER=database
# Optional, with these defaults (config/database.php):
# DB_JOURNAL_MODE=wal  DB_BUSY_TIMEOUT=10000  DB_SYNCHRONOUS=normal  DB_TRANSACTION_MODE=IMMEDIATE
```

Restore a slim backup with the stack stopped:

```bash
make down
gunzip -c ~/Backups/deployme/deployme-<stamp>.sqlite.gz > src/api/storage/database/deployme.sqlite
rm -f src/api/storage/database/deployme.sqlite-wal src/api/storage/database/deployme.sqlite-shm
make up
```

## Development and production mode

Separately from the stack, each machine runs deploy.me in one of two modes:

| | `dev` (development, the default) | `prod` (production) |
|---|---|---|
| **The code** | mounted from `src/api`: an edit shows up on the next request | baked into the image (`deployme-php:prod`), with its `--no-dev` dependencies and built assets |
| **Mounted from `src/api`** | all of it | only `.env` (read-only) and `storage/` (logs, deploy logs, mirrors) |
| **`src/api/.env`** | `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug` | `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` |
| **Caches** | cleared when a container starts | config, routes, events and views built when a container starts |
| **OPcache** | re-checks file timestamps every 2 s | never re-checks them; on for the CLI too, with a file cache |
| **Frontend** | `make assets`, or `make dev` for Vite with hot reload | built into the image |
| **Compose files** | as in the stack table above | plus `compose.prod.yml`, before `compose.host.yml` |

Development mode is for working on deploy.me itself. **Once you only use it, switch to
production mode**: pages answer faster, an error page shows no stack trace, and the
containers run exactly the code you built.

Switch with `bin/configure` (the first run asks; later runs keep the mode until told
otherwise), then rebuild and restart:

```bash
bin/configure --mode prod && make build && make up   # to production
bin/configure --mode dev && make build && make up    # back to development
```

`bin/configure` sets the three values above in `src/api/.env` and leaves every other line,
`APP_KEY` above all, as it is. Going back to dev restores the development values.

**In production mode a code change needs `make build && make up`.** That covers a
`git pull`, an edit in `src/api`, and the CSS and JavaScript: `make assets` only says so,
and `make dev` refuses until you switch back. An edit to `src/api/.env` needs
`make restart`, since the config is cached when a container starts. The test suite and
`make composer` belong to development mode (the image has neither the tests nor the dev
dependencies). `make doctor` says which mode the machine is in, and in production checks
that debug is off, the caches are built and OPcache trusts the code.

`compose.prod.yml` uses Compose's `!override` tag (Compose 2.24.4 or later) to replace
the base file's mounts, since an overlay can't otherwise take a mount away.

## What `bin/configure` writes

Two files, both git-ignored. Running it again rewrites them and keeps the database password.

**`.env`** (read by docker compose and the scripts):

| Key | Meaning |
|---|---|
| `COMPOSE_FILE` | The compose files for this machine's stack mode |
| `DEPLOYME_STACK` | `standalone` or `shared` |
| `DEPLOYME_MODE` | `dev` or `prod` ([the two modes](#development-and-production-mode)) |
| `DEPLOYME_FLAVOUR` | `full` or `slim` ([the two flavours](#two-flavours-full-and-slim)) |
| `DEPLOYME_OS` | `macos`, `linux` or `wsl` |
| `DEPLOYME_NETWORK` | The edge's Docker network |
| `DEPLOYME_UID`, `DEPLOYME_GID` | Who the containers run as (0 on macOS) |
| `DEPLOYME_TZ` | Your timezone |
| `DEPLOYME_PROJECT_ROOTS` | The folders projects are imported from, `:`-separated |
| `DEPLOYME_POSTGRES_PASSWORD` | Standalone full only: the Postgres superuser's password |
| `DEPLOYME_PHP_BASE` | Only with `--build-from-source`: `deployme-php-base:local` (slim: `:local-slim`), the base compiled here |
| `DEPLOYME_HTTP_PORT`, `DEPLOYME_HTTPS_PORT` | Optional, added by hand and kept: the ports the slim flavour's own edge publishes on 127.0.0.1 (80 and 443 by default; the site still answers as `deployme.localhost`) |

**`compose.host.yml`**: the mounts only this machine has, namely each project root
(read-only for the web container, read-write for the worker) and the ssh-agent socket.

The app's own settings stay in `src/api/.env`, including `APP_KEY`. Back that key up.
`bin/configure` only touches its `APP_ENV`, `APP_DEBUG` and `LOG_LEVEL`, for the mode, and
`DB_CONNECTION`, `DB_DATABASE`, `CACHE_STORE` and `DEPLOYME_QUEUE_DRIVER`, for the flavour. In
production mode `make db-create` also generates `APP_KEY` and the Reverb credentials when
they're blank, because the containers mount that file read-only.

## Updating, stopping, uninstalling

```bash
bin/update                   # update to the newest release: backup, rebuild, restart, doctor, back out if it fails (make update)
bin/update auto patch        # optional: do the patch releases by itself at night (off by default; docs/usage.md chapter 16)
git pull && make build up    # or by hand, to follow main instead of the releases
make down                    # stop (data is kept)
make up                      # start again
```

To uninstall a standalone stack **and delete its data** (the database, the CA; in the slim
flavour, also delete `src/api/storage/database/deployme.sqlite*`):

```bash
make backup                       # first, if you want to keep anything
docker compose down -v --rmi local
```

Then remove the CA you trusted: on macOS from Keychain Access, on Linux the
`deployme-caddy-local.crt` in `/usr/local/share/ca-certificates`, on Windows
`certmgr.msc` → Trusted Root → "Caddy Local Authority".

## Troubleshooting

Start with `make doctor`. It says which part fails.

| Symptom | Fix |
|---|---|
| `Port 443 is already published by another container` | Another edge or web server holds 80/443. Stop it, or use the `shared` mode if it's a caddy-docker-proxy |
| The browser warns about the certificate | `bin/trust-ca`, then restart the browser. Firefox needs its own import |
| `Bad owner or permissions on /root/.ssh/config` | The containers run as root on Linux. Run `bin/configure` (it sets your UID), then `make build up` |
| `Permission denied` on files in `src/api` | Files left by an older root run: `sudo chown -R $USER: src/api`, then `make build up` |
| `docker: permission denied` (Linux) | Add yourself to the `docker` group and log in again |
| Docker isn't reachable in WSL | Docker Desktop → Settings → Resources → WSL integration → on for your distribution |
| `deployme.localhost` doesn't resolve | Browsers and curl resolve `*.localhost` themselves. For other tools, add `127.0.0.1 deployme.localhost` to `/etc/hosts` (Windows: `C:\Windows\System32\drivers\etc\hosts`) |
| An edit in `src/api` doesn't show up | Production mode bakes the code into the image: `make build && make up`, or `bin/configure --mode dev` while you work on it |
| `make dev` says it needs development mode | `bin/configure --mode dev && make build && make up` |
| A project folder isn't offered for import | Add its parent as a root: `bin/configure --roots ~/Projects:~/work`, then `make up` |
| `database is locked` in the log (slim) | Another writer held SQLite longer than the busy timeout (10 s): raise `DB_BUSY_TIMEOUT` in `src/api/.env` and `make restart`, or move to the full flavour if it keeps happening |
| After a flavour switch the old containers still run | `docker compose down --remove-orphans`, then `make up` |
