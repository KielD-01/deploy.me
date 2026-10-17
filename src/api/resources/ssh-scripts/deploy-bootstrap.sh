# Creates a target's folders. Inputs: TARGET, VERSIONABLE (0/1).
set -eo pipefail
umask 022

mkdir -p -- "$TARGET/.deploy/runs" "$TARGET/.deploy/incoming"
chmod 0750 -- "$TARGET/.deploy"

if [ "$VERSIONABLE" = 1 ]; then
  mkdir -p -- "$TARGET/releases" "$TARGET/shared"
  echo "✓ $TARGET/releases and $TARGET/shared are ready"
  echo "::deployme::layout=releases"
else
  echo "✓ $TARGET is ready"
  echo "::deployme::layout=in_place"
fi

ls -la -- "$TARGET" | sed 1d
