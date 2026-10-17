# Gives the target's deploy lock back, if this deploy holds it. Inputs:
# TARGET, OWNER (deployment id), FORCE (1 breaks the lock by hand: removes it
# whoever holds it, and says whose it was).
set -eo pipefail

lock="$TARGET/.deploy/lock"

if [ ! -d "$lock" ]; then
  if [ "${FORCE:-0}" = 1 ]; then
    echo "· $TARGET wasn't locked"
    echo "::deployme::broken=0"
  fi
  exit 0
fi

# The guard deploy-lease takes, so the owner can't change halfway through.
if command -v flock >/dev/null 2>&1; then
  exec 9<"$TARGET/.deploy"
  flock -w 30 9 || { echo "✗ Something else kept $TARGET/.deploy busy for 30 s"; exit 21; }
fi

holder=""
since=""
read -r holder since < "$lock/owner" 2>/dev/null || true

if [ "${FORCE:-0}" = 1 ]; then
  minutes=""
  case "$since" in ''|*[!0-9]*) ;; *) minutes=$(( ($(date +%s) - since) / 60 )) ;; esac
  rm -rf -- "$lock"
  if [ -n "$holder" ]; then
    echo "✓ Broke the lock deploy #$holder held on $TARGET${minutes:+ for $minutes min}"
  else
    echo "✓ Broke the lock on $TARGET"
  fi
  echo "::deployme::broken=1"
  echo "::deployme::previous=$holder"
  exit 0
fi

if [ "$holder" = "$OWNER" ] || [ -z "$holder" ]; then
  rm -rf -- "$lock"
  echo "✓ Unlocked $TARGET"
else
  echo "! The lock now belongs to deploy #$holder; left it alone"
fi
