# Points current at a release: a new link renamed over the old one, which is
# atomic with GNU mv. Inputs: TARGET, RELEASE (a folder name under releases/).
set -eo pipefail
cd -- "$TARGET"

case "$RELEASE" in ""|*/*|.*) echo "✗ $RELEASE isn't a release name"; exit 2 ;; esac
[ -d "releases/$RELEASE" ] || { echo "✗ releases/$RELEASE isn't on the server"; exit 3; }
if [ -e current ] && [ ! -L current ]; then
  echo "✗ $TARGET/current is a folder, not a link. Move it out of the way first."
  exit 4
fi

was=$(readlink current 2>/dev/null || true)
ln -sfn "releases/$RELEASE" ".current.$$"
if mv --version 2>/dev/null | grep -q GNU; then
  mv -Tf ".current.$$" current
else
  rm -f ".current.$$"
  ln -sfn "releases/$RELEASE" current
fi

echo "✓ current → releases/$RELEASE${was:+ (was ${was#releases/})}"
echo "::deployme::was=${was#releases/}"
