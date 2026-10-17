# 🧩 Installing deploy.me on macOS, Linux and Windows

deploy.me runs in Docker on your own machine, the same way on all three systems:

| | 🍎 macOS | 🐧 Linux | 🪟 Windows |
|---|---|---|---|
| **Docker** | Docker Desktop or OrbStack | Docker Engine + the Compose plugin (or Docker Desktop) | Docker Desktop with the WSL2 backend |
| **Where it runs** | your Mac | your machine | a WSL2 distribution (Ubuntu) |
| **Install** | `bin/install` | `bin/install` | `bin\install.ps1` in PowerShell |
| **Containers run as** | root (Docker maps ownership) | your user's UID | your WSL user's UID |
| **Local CA trusted in** | the login keychain | the system store + NSS (Chrome) | WSL **and** Windows (Edge, Chrome) |
| **Nightly backup** | launchd | systemd user timer, or cron | systemd in WSL, or cron |

You end up at **https://deployme.localhost**, signing in with a passkey or a password.

- [Before you start](#before-you-start)
- [macOS](#-macos)
- [Linux](#-linux)
- [Windows (WSL2)](#-windows-wsl2)
- [Two stack modes](#two-stack-modes)
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
git clone https://github.com/KielD-01/deploy.me.git
cd deploy.me
bin/install
```

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
git clone https://github.com/KielD-01/deploy.me.git
cd deploy.me
bin/install
```

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

Prefer to do it by hand? Open Ubuntu and follow the [Linux](#-linux) steps. Docker is
already there through Docker Desktop.

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

## Two stack modes

deploy.me needs PostgreSQL, Valkey (Redis) and an HTTPS edge. It can bring its own, or use
a stack you already run:

| | `standalone` | `shared` |
|---|---|---|
| **Used when** | nothing else is there (the default on a new machine) | a `proxy-network` with `postgres` and `caddy` containers is already running |
| **Compose files** | `docker-compose.local.yml` + `compose.standalone.yml` + `compose.host.yml` | `docker-compose.local.yml` + `compose.host.yml` |
| **Postgres / Valkey / edge** | `deployme-postgres`, `deployme-valkey`, `deployme-caddy` (volumes `deployme_postgres_data`, …) | the shared stack's own containers |
| **Network** | `deployme-edge` | `proxy-network` (external) |

`bin/configure` picks one. To choose yourself, pass `bin/configure --stack standalone` or
`--stack shared`, then run `make up`. Either way the app finds the database as `postgres`
and the cache as `redis`, so nothing in `src/api/.env` changes.

## What `bin/configure` writes

Two files, both git-ignored. Running it again rewrites them and keeps the database password.

**`.env`** (read by docker compose and the scripts):

| Key | Meaning |
|---|---|
| `COMPOSE_FILE` | The compose files for this machine's stack mode |
| `DEPLOYME_STACK` | `standalone` or `shared` |
| `DEPLOYME_OS` | `macos`, `linux` or `wsl` |
| `DEPLOYME_NETWORK` | The edge's Docker network |
| `DEPLOYME_UID`, `DEPLOYME_GID` | Who the containers run as (0 on macOS) |
| `DEPLOYME_TZ` | Your timezone |
| `DEPLOYME_PROJECT_ROOTS` | The folders projects are imported from, `:`-separated |
| `DEPLOYME_POSTGRES_PASSWORD` | Standalone only: the Postgres superuser's password |

**`compose.host.yml`**: the mounts only this machine has, namely each project root
(read-only for the web container, read-write for the worker) and the ssh-agent socket.

The app's own settings stay in `src/api/.env`, including `APP_KEY`. Back that key up.

## Updating, stopping, uninstalling

```bash
git pull && make build up    # update: rebuild the image; the web container migrates on start
make down                    # stop (data is kept)
make up                      # start again
```

To uninstall a standalone stack **and delete its data** (the database, the CA):

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
| A project folder isn't offered for import | Add its parent as a root: `bin/configure --roots ~/Projects:~/work`, then `make up` |
