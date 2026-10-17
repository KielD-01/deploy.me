# Links the folders and files every release shares into a new release.
# Inputs: RELEASE (the release folder), SHARED (the shared/ folder), DIRS and
# FILES (paths inside the project, one per line). A shared path shared/ doesn't
# have yet starts from the release's own copy, a folder or a file. With no copy
# anywhere, a folder starts empty, but a path named like a file (.env,
# settings.local.php) isn't linked: an empty folder in its place would only
# break whatever reads it.
set -eo pipefail
umask 022

inside() {
  case "$1" in ""|/*|..|../*|*/..|*/../*) return 1 ;; esac
  return 0
}

# A dot in the last part of the path: .env, .env.production, config.local.php.
named_like_file() {
  case "${1##*/}" in *.*) return 0 ;; *) return 1 ;; esac
}

while IFS= read -r dir; do
  [ -n "$dir" ] || continue
  inside "$dir" || { echo "! Skipped $dir: not a path inside the project"; continue; }
  src="$RELEASE/$dir"
  dst="$SHARED/$dir"
  if [ ! -e "$dst" ]; then
    if [ -e "$src" ] && [ ! -L "$src" ]; then
      mkdir -p -- "$(dirname -- "$dst")"
      mv -- "$src" "$dst"
      echo "✓ shared/$dir starts from this release's copy"
    elif named_like_file "$dir"; then
      echo "! Not linked: $dir isn't in shared/ yet, and this release has no copy of it"
      continue
    else
      mkdir -p -- "$dst"
    fi
  fi
  rm -rf -- "$src"
  mkdir -p -- "$(dirname -- "$src")"
  ln -s -- "$dst" "$src"
  echo "✓ $dir → shared/$dir"
done <<< "$DIRS"

while IFS= read -r file; do
  [ -n "$file" ] || continue
  inside "$file" || { echo "! Skipped $file: not a path inside the project"; continue; }
  src="$RELEASE/$file"
  dst="$SHARED/$file"
  mkdir -p -- "$(dirname -- "$dst")" "$(dirname -- "$src")"
  rm -rf -- "$src"
  ln -s -- "$dst" "$src"
  echo "✓ $file → shared/$file"
done <<< "$FILES"
