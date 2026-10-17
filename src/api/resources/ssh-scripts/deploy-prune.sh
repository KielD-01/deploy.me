# Removes old release folders. Inputs: TARGET, NAMES (release folder names,
# one per line), KEEP (the live release: never removed, whatever NAMES says).
set -eo pipefail
cd -- "$TARGET/releases"

live=$(readlink "$TARGET/current" 2>/dev/null || true)
live=${live#releases/}

while IFS= read -r name; do
  [ -n "$name" ] || continue
  case "$name" in *[!0-9-]*|-*) echo "! Skipped $name: not a release name"; continue ;; esac
  if [ "$name" = "$live" ] || [ "$name" = "$KEEP" ]; then
    echo "! Kept $name: it's live"
    continue
  fi
  if [ ! -d "$name" ]; then
    echo "· releases/$name was already gone"
    continue
  fi
  rm -rf -- "$name"
  echo "✓ Removed releases/$name"
done <<< "$NAMES"
