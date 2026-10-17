# Keeps deploy.me's own shared links (storage folders, env files) out of a server
# build's context: through a symlink they'd reach the image as a link to a folder
# that isn't there (`COPY src/api .` then `mkdir storage/…` fails). Inputs: DIR,
# DOCKERFILE and CONTEXT (relative to DIR), EXCLUDE (paths relative to CONTEXT, one
# per line). Writes DOCKERFILE.dockerignore, which BuildKit prefers for that
# Dockerfile: the project's own rules (its Dockerfile-specific ignore file, else
# the context's .dockerignore) and one line per shared path. Written to a new file
# and moved over it, so a hard link into the previous release is left alone.
set -eo pipefail
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }

target="$DOCKERFILE.dockerignore"
tmp="$target.deployme-$$"
{
  if [ -f "$target" ]; then
    cat -- "$target"
  elif [ -f "$CONTEXT/.dockerignore" ]; then
    cat -- "$CONTEXT/.dockerignore"
  fi
  printf '\n# deploy.me: this release'\''s shared links, never part of an image\n'
  printf '%s\n' "$EXCLUDE"
} > "$tmp"
mv -f -- "$tmp" "$target"

echo "✓ $target keeps $(printf '%s\n' "$EXCLUDE" | grep -c . || true) shared path(s) out of the build"
