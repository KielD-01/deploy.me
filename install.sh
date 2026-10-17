#!/usr/bin/env bash
# deploy.me in one line, on macOS, Linux, or Windows inside WSL2:
#
#   curl -fsSL https://raw.githubusercontent.com/KielD-01/deploy.me/main/install.sh | bash
#
# It clones deploy.me into ~/deploy.me (or $DEPLOYME_DIR), or updates the copy
# already there, and runs its bin/install, which checks the tools, builds and
# starts the stack, trusts the local CA and prints the link that sets up your
# passkey or password. Options after `bash -s --` go to bin/install:
#
#   curl -fsSL …/install.sh | bash -s -- --stack standalone
#   curl -fsSL …/install.sh | DEPLOYME_DIR=~/tools/deploy.me bash
#
# Read it first if you like: it's this file, and it runs nothing else.
set -euo pipefail

repo="${DEPLOYME_REPO_URL:-https://github.com/KielD-01/deploy.me.git}"
dir="${DEPLOYME_DIR:-$HOME/deploy.me}"
branch="${DEPLOYME_BRANCH:-main}"

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }

case "$(uname -s)" in
    Darwin|Linux) ;;
    *) die "On Windows, run this inside WSL2, or in PowerShell: irm https://raw.githubusercontent.com/KielD-01/deploy.me/main/bin/install.ps1 | iex" ;;
esac

command -v git >/dev/null || die "git is missing. macOS: xcode-select --install · Debian/Ubuntu: sudo apt install git"
command -v docker >/dev/null || die "Docker is missing. macOS: Docker Desktop or OrbStack · Linux: https://docs.docker.com/engine/install/"

if [ -d "$dir/.git" ]; then
    say "Updating deploy.me in $dir"
    git -C "$dir" pull --ff-only --quiet
elif [ -e "$dir" ] && [ -n "$(ls -A "$dir" 2>/dev/null)" ]; then
    die "$dir exists and isn't a deploy.me checkout. Move it, or set DEPLOYME_DIR to another folder."
else
    say "Cloning deploy.me into $dir"
    git clone --quiet --branch "$branch" "$repo" "$dir"
fi

cd "$dir"
# Piped from curl, stdin is this script: give bin/install the terminal for its questions.
if [ -t 1 ] && { true </dev/tty; } 2>/dev/null; then
    exec bin/install "$@" </dev/tty
fi
exec bin/install "$@"
