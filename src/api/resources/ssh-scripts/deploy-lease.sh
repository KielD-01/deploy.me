# Takes the target's deploy lock: a directory, because mkdir is atomic.
# Inputs: TARGET, OWNER (deployment id), STALE_AFTER (seconds), TAKEOVER (a
# holder deploy.me knows is finished: its lock is taken over while it still
# names that holder; empty otherwise). Who owns the lock only changes under
# flock on .deploy/, so two deploys can't both take the same lock over. It
# makes .deploy/runs too, where the run's scripts note their process group,
# and .deploy/incoming, where an in-place release is staged.
set -eo pipefail

lock="$TARGET/.deploy/lock"
mkdir -p -- "$TARGET/.deploy/runs" "$TARGET/.deploy/incoming"

if command -v flock >/dev/null 2>&1; then
  exec 9<"$TARGET/.deploy"
  flock -w 30 9 || { echo "✗ Something else kept $TARGET/.deploy busy for 30 s"; exit 21; }
fi

if mkdir -- "$lock" 2>/dev/null; then
  printf '%s %s\n' "$OWNER" "$(date +%s)" > "$lock/owner"
  echo "✓ Locked $TARGET for deploy #$OWNER"
  echo "::deployme::lease=acquired"
  exit 0
fi

holder=unknown
since=0
read -r holder since < "$lock/owner" 2>/dev/null || true
age=$(( $(date +%s) - ${since:-0} ))

if [ "$holder" = "$OWNER" ]; then
  echo "✓ Deploy #$OWNER already holds the lock"
  echo "::deployme::lease=held"
  exit 0
fi

if [ -n "${TAKEOVER:-}" ] && [ "$holder" = "$TAKEOVER" ]; then
  printf '%s %s\n' "$OWNER" "$(date +%s)" > "$lock/owner"
  echo "! Took over the lock deploy #$holder left behind"
  echo "::deployme::lease=taken_over"
  echo "::deployme::previous=$holder"
  exit 0
fi

if [ "$age" -gt "$STALE_AFTER" ]; then
  printf '%s %s\n' "$OWNER" "$(date +%s)" > "$lock/owner"
  echo "! Took over a lock deploy #$holder left behind $(( age / 60 )) min ago"
  echo "::deployme::lease=stolen"
  echo "::deployme::previous=$holder"
  exit 0
fi

echo "✗ Deploy #$holder has held the lock for $(( age / 60 )) min"
echo "::deployme::holder=$holder"
echo "::deployme::age=$age"
exit 20
