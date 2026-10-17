# The server fetches the commit itself, into a mirror of its own, and
# unpacks it where the release goes. Inputs: REMOTE, MIRROR, REF (a commit or
# a branch), DEST. The server's own keys answer the remote (a deploy key).
set -o pipefail
export GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND="ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

command -v git >/dev/null 2>&1 || { echo "✗ git isn't installed on the server"; exit 12; }

if [ -d "$MIRROR" ]; then
  git --git-dir="$MIRROR" remote set-url origin "$REMOTE"
  echo "\$ git fetch $REMOTE"
  if ! git --git-dir="$MIRROR" fetch --prune --quiet origin '+refs/heads/*:refs/heads/*' 2>&1; then
    echo "✗ The server couldn't fetch $REMOTE. Does it have a deploy key for it?"
    exit 42
  fi
else
  mkdir -p -- "$(dirname -- "$MIRROR")"
  echo "\$ git clone --mirror $REMOTE"
  if ! git clone --mirror --quiet "$REMOTE" "$MIRROR" 2>&1; then
    rm -rf -- "$MIRROR"
    echo "✗ The server couldn't clone $REMOTE. Does it have a deploy key for it?"
    exit 42
  fi
fi

if ! sha=$(git --git-dir="$MIRROR" rev-parse --verify --quiet "$REF^{commit}"); then
  echo "✗ $REF isn't in $REMOTE"
  exit 43
fi

mkdir -p -- "$DEST"
git --git-dir="$MIRROR" archive --format=tar "$sha" | tar -x -C "$DEST" || { echo "✗ Couldn't unpack $sha into $DEST"; exit 44; }

echo "✓ $(git --git-dir="$MIRROR" log -1 --format='%h %s' "$sha") unpacked into $DEST"
echo "::deployme::commit=$sha"
echo "::deployme::subject=$(git --git-dir="$MIRROR" log -1 --format=%s "$sha")"
