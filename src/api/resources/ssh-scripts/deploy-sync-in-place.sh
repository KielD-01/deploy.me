# Puts an in-place release into place: a local rsync from its staged folder
# (.deploy/incoming/<release>) into the project folder, which also removes
# what the release no longer has. What belongs to the server stays: FILTERS
# (rsync filter rules, one per line) keep deploy.me's .deploy/, .git, the env
# files and the shared paths out of it, and protect what the project's
# .gitignore files name (vendor/, node_modules/) without keeping a hook's
# fresh build of it out. Inputs: TARGET, RELEASE (the staged folder's name),
# FILTERS. The staged folder goes afterwards, with any an earlier deploy that
# never went live left behind.
set -eo pipefail

case "$RELEASE" in ""|*/*|.*) echo "✗ $RELEASE isn't a release name"; exit 2 ;; esac
incoming="$TARGET/.deploy/incoming"
staged="$incoming/$RELEASE"
[ -d "$staged" ] || { echo "✗ .deploy/incoming/$RELEASE isn't on the server"; exit 3; }
command -v rsync >/dev/null 2>&1 || { echo "✗ rsync isn't installed on the server"; exit 16; }

rules=()
while IFS= read -r rule; do
  [ -n "$rule" ] && rules+=("--filter=$rule")
done <<< "$FILTERS"

# By content (--checksum): a file changed within the same second at the same
# size still goes. Deletions come after the transfer, so the new .gitignore
# files are the ones that protect.
changes=$(rsync -a --delete-after --checksum --itemize-changes "${rules[@]}" -- "$staged/" "$TARGET/")

updated=$(printf '%s\n' "$changes" | grep -c '^>f' || true)
deleted=$(printf '%s\n' "$changes" | grep -c '^\*deleting' || true)
echo "✓ Synced release $RELEASE into $TARGET: $updated file(s) updated, $deleted removed"
printf '%s\n' "$changes" | grep '^\*deleting' | head -20 | sed 's/^\*deleting *//; s/^/  − /' || true
[ "$deleted" -gt 20 ] && echo "  … and $(( deleted - 20 )) more"
echo "::deployme::updated=$updated"
echo "::deployme::deleted=$deleted"

find "$incoming" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
