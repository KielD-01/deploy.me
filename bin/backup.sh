#!/bin/bash
# A copy of the two things deploy.me cannot be rebuilt from: its database (the
# GitHub tokens and the encrypted env files, with their history) and the APP_KEY
# that decrypts them. Everything else — the app, the images, the releases — can
# be built again from git and from the servers.
#
#   bin/backup.sh                 once, into ~/Backups/deployme
#   DEPLOYME_BACKUP_DIR=… bin/backup.sh   somewhere else
#   bin/backup.sh install         nightly from here on, at 03:20
#   bin/backup.sh status          when it last ran, and what is kept
#
# Nightly through a LaunchAgent on macOS (~/Library/LaunchAgents/
# dev.deployme.backup.plist), a systemd user timer on Linux and WSL2 where
# systemd runs, and a crontab line elsewhere. Keeps the last 14 of each; the key
# is copied, never printed. Restore a dump with (deployme-postgres on the
# standalone stack):
#
#   gunzip -c deployme-<stamp>.sql.gz | docker exec -i postgres psql -U postgres -d deployme
set -euo pipefail

# This repository, following the symlink some people keep in ~/.local/bin.
source_path=${BASH_SOURCE[0]}
while [ -L "$source_path" ]; do
    target=$(readlink -- "$source_path")
    case "$target" in
        /*) source_path=$target ;;
        *) source_path=$(dirname -- "$source_path")/$target ;;
    esac
done
# Absolute, because `make backup-install` runs this as bin/backup.sh: linked as
# that, ~/.local/bin/deployme-backup pointed at ~/.local/bin/bin/backup.sh, which
# doesn't exist, and launchd failed every night (exit 78) without a word.
source_path="$(cd -- "$(dirname -- "$source_path")" && pwd)/$(basename -- "$source_path")"

repo="${DEPLOYME_REPO:-$(cd -- "$(dirname -- "$source_path")/.." && pwd)}"
. "$repo/bin/lib.sh"
os=$(deployme_os)
pg=$(deployme_pg_container)
dest="${DEPLOYME_BACKUP_DIR:-$HOME/Backups/deployme}"
keep=14
stamp=$(date +%Y%m%d-%H%M)

mkdir -p "$dest"
chmod 700 "$dest"

plist="$HOME/Library/LaunchAgents/dev.deployme.backup.plist"
link="$HOME/.local/bin/deployme-backup"

unit_dir="$HOME/.config/systemd/user"

# Linux and WSL2: a systemd user timer where systemd runs, else a crontab line.
install_linux() {
    if systemctl --user show-environment >/dev/null 2>&1; then
        mkdir -p "$unit_dir"
        cat > "$unit_dir/deployme-backup.service" <<UNIT
[Unit]
Description=deploy.me backup (database and APP_KEY)

[Service]
Type=oneshot
ExecStart=$link
UNIT
        cat > "$unit_dir/deployme-backup.timer" <<UNIT
[Unit]
Description=deploy.me backup at 03:20

[Timer]
OnCalendar=*-*-* 03:20:00
Persistent=true

[Install]
WantedBy=timers.target
UNIT
        systemctl --user daemon-reload
        systemctl --user enable --now deployme-backup.timer >/dev/null
        echo "✓ deployme-backup.timer runs $link at 03:20, into $dest"
        # if, not &&: a false test as the function's last command would fail it under set -e.
        if command -v loginctl >/dev/null && ! loginctl show-user "$USER" -p Linger 2>/dev/null | grep -q yes; then
            echo "· It runs while you're logged in; 'sudo loginctl enable-linger $USER' makes it run regardless"
        fi
    else
        # No crontab yet (crontab -l fails) or nothing else in it (grep -v finds
        # no line) is the usual first install, not a failure.
        ( { crontab -l 2>/dev/null || true; } | { grep -v deployme-backup || true; }; echo "20 3 * * * $link >> $dest/cron.log 2>&1" ) | crontab -
        echo "✓ A crontab line runs $link at 03:20, into $dest"
        if [ "$os" = wsl ]; then
            echo "· WSL runs cron only while the distribution is up; turn systemd on in /etc/wsl.conf for a timer"
        fi
    fi
}

# Nightly from here on: this file linked into ~/.local/bin (so the job survives
# a checkout moving) and a LaunchAgent, timer or cron line that runs it while
# you're asleep.
install_agent() {
    mkdir -p "$HOME/.local/bin"
    ln -sfn "$source_path" "$link"

    if [ "$os" != macos ]; then
        install_linux
        return
    fi

    mkdir -p "$HOME/Library/LaunchAgents"

    cat > "$plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>dev.deployme.backup</string>
    <key>ProgramArguments</key>
    <array>
        <string>$link</string>
    </array>
    <key>StartCalendarInterval</key>
    <dict>
        <key>Hour</key>
        <integer>3</integer>
        <key>Minute</key>
        <integer>20</integer>
    </dict>
    <key>StandardOutPath</key>
    <string>$dest/launchd.out.log</string>
    <key>StandardErrorPath</key>
    <string>$dest/launchd.err.log</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/usr/local/bin:/opt/homebrew/bin:$HOME/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>
    </dict>
</dict>
</plist>
PLIST

    launchctl unload "$plist" 2> /dev/null || true
    launchctl load "$plist"
    echo "✓ dev.deployme.backup runs $link at 03:20, into $dest"
}

status() {
    if [ "$os" = macos ] && launchctl list dev.deployme.backup > /dev/null 2>&1; then
        echo "✓ dev.deployme.backup is loaded (03:20 daily)"
    elif [ "$os" != macos ] && systemctl --user is-enabled deployme-backup.timer > /dev/null 2>&1; then
        echo "✓ deployme-backup.timer is enabled (03:20 daily)"
    elif [ "$os" != macos ] && crontab -l 2>/dev/null | grep -q deployme-backup; then
        echo "✓ a crontab line runs it at 03:20"
    else
        echo "· Not scheduled yet — bin/backup.sh install sets it up"
    fi

    [ -f "$dest/backup.log" ] && tail -n 3 "$dest/backup.log"
    # shellcheck disable=SC2012
    echo "· $(ls -1 "$dest"/deployme-*.sql.gz 2> /dev/null | wc -l | tr -d ' ') dumps kept in $dest"
}

case "${1:-}" in
    install) install_agent; exit 0 ;;
    status) status; exit 0 ;;
    '') ;;
    *) echo "Usage: backup.sh [install|status]" >&2; exit 64 ;;
esac

if ! docker inspect "$pg" > /dev/null 2>&1; then
    echo "$(date -Iseconds)  the $pg container isn't running; nothing backed up" >&2
    exit 1
fi

docker exec "$pg" pg_dump -U postgres --no-owner --clean --if-exists deployme | gzip > "$dest/deployme-$stamp.sql.gz"

# Without APP_KEY the credentials and env files in that dump stay unreadable.
if [ -f "$repo/src/api/.env" ]; then
    install -m 600 "$repo/src/api/.env" "$dest/env-$stamp"
else
    echo "$(date -Iseconds)  $repo/src/api/.env is missing, so no key was copied" >&2
fi

for pattern in "deployme-*.sql.gz" "env-*"; do
    # shellcheck disable=SC2012
    ls -1t "$dest"/$pattern 2>/dev/null | tail -n "+$((keep + 1))" | while read -r old; do rm -f -- "$old"; done
done

# A copy that survives this machine. The dumps go, the key does not: together
# they are the whole of deploy.me's secrets, and iCloud is not where the second
# half belongs — keep APP_KEY in a password manager instead.
offsite="${DEPLOYME_OFFSITE_DIR:-}"
if [ -z "$offsite" ] && [ -d "$HOME/Library/Mobile Documents/com~apple~CloudDocs" ]; then
    offsite="$HOME/Library/Mobile Documents/com~apple~CloudDocs/Backups/deployme"
fi

#
# Under launchd macOS lets this script create and delete its own files in iCloud
# Drive but not list the folder, so the copies it made are remembered in
# offsite.list and the old ones removed by name. A failed copy is reported and
# the local backup still counts.
if [ -n "$offsite" ] && mkdir -p "$offsite" 2>/dev/null; then
    if cp "$dest/deployme-$stamp.sql.gz" "$offsite/"; then
        echo "deployme-$stamp.sql.gz" >> "$dest/offsite.list"
        total=$(wc -l < "$dest/offsite.list" | tr -d ' ')
        if [ "$total" -gt "$keep" ]; then
            head -n "$((total - keep))" "$dest/offsite.list" | while read -r old; do rm -f -- "$offsite/$old" || true; done
            tail -n "$keep" "$dest/offsite.list" > "$dest/offsite.list.new" && mv "$dest/offsite.list.new" "$dest/offsite.list"
        fi
    else
        echo "$(date -Iseconds)  couldn't copy deployme-$stamp.sql.gz to $offsite" >&2
    fi
fi

printf '%s  %s  %s\n' "$(date -Iseconds)" "$(du -h "$dest/deployme-$stamp.sql.gz" | cut -f1)" "deployme-$stamp.sql.gz" >> "$dest/backup.log"
