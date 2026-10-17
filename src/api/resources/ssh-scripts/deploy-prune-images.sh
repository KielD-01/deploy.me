# Removes the images old releases pinned. Inputs: DIR (the live release, where
# compose resolves the image names), STACK, FILES (compose files inside DIR,
# one per line), TAGS (tags to remove, one per line), KEEP_TAGS (tags to leave
# alone: the live release's and the rollback target's), REFS (whole image
# references to remove), KEEP_REFS. Only repositories the live release resolves
# with a tag this project pinned are touched. An image something still uses is
# kept, not an error: the release is live by the time this runs.
set -o pipefail
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }
export COMPOSE_ANSI=never

args=(--ansi never -p "$STACK" --project-directory "$DIR")
while IFS= read -r file; do
  [ -n "$file" ] || continue
  case "$file" in /*|..|../*|*/..|*/../*) echo "✗ $file isn't a path inside the project"; exit 2 ;; esac
  [ -f "$file" ] || { echo "✗ $file isn't in $DIR"; exit 3; }
  args+=(-f "$file")
done <<< "$FILES"

# The release's own image pins (IMAGE_TAG and the like) go over the shared .env.
if [ -f .deployme-pins.env ]; then
  [ -f .env ] && args+=(--env-file "$DIR/.env")
  args+=(--env-file "$DIR/.deployme-pins.env")
fi

in_list() { printf '%s\n' "$2" | grep -qxF -- "$1"; }

# What the live release runs. Its images are never removed, and the ones whose
# tag this project pinned say which repositories the pins belong to.
live=$(docker compose "${args[@]}" config --images 2>/dev/null | sed '/^[[:space:]]*$/d' | sort -u)
[ -n "$live" ] || echo "! Compose didn't say which images the live release uses; only whole references are removed"

repos=""
while IFS= read -r ref; do
  [ -n "$ref" ] || continue
  case "$ref" in *:*) ;; *) continue ;; esac
  tag=${ref##*:}
  case "$tag" in */*) continue ;; esac
  in_list "$tag" "$KEEP_TAGS" || continue
  repos="$repos${ref%:*}
"
done <<< "$live"
repos=$(printf '%s' "$repos" | sort -u)

removed=0; kept=0; gone=0

remove_ref() {
  case "$1" in -*|*[!A-Za-z0-9._:/-]*) echo "! Skipped $1: that isn't an image reference"; return ;; esac

  if out=$(docker image rm "$1" 2>&1); then
    echo "✓ Removed $1"
    removed=$((removed + 1))
    return
  fi

  case "$out" in
    *"No such image"*|*"reference does not exist"*) gone=$((gone + 1)) ;;
    *conflict*|*"is being used"*|*"in use"*) echo "! Kept $1: something on the server still uses it"; kept=$((kept + 1)) ;;
    *) echo "! Couldn't remove $1: $(printf '%s' "$out" | tail -n 1)" ;;
  esac
}

while IFS= read -r tag; do
  [ -n "$tag" ] || continue
  in_list "$tag" "$KEEP_TAGS" && continue
  while IFS= read -r repo; do
    [ -n "$repo" ] || continue
    in_list "$repo:$tag" "$live" && continue
    remove_ref "$repo:$tag"
  done <<< "$repos"
done <<< "$TAGS"

while IFS= read -r ref; do
  [ -n "$ref" ] || continue
  in_list "$ref" "$KEEP_REFS" && continue
  in_list "$ref" "$live" && continue
  remove_ref "$ref"
done <<< "$REFS"

[ "$removed" -gt 0 ] || [ "$kept" -gt 0 ] || echo "· No image of an old release was still on the server"
echo "::deployme::removed=$removed"
echo "::deployme::kept=$kept"
echo "::deployme::gone=$gone"
