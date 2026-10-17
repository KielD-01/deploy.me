# Reads a project's env files off the server, so deploy.me can keep them the
# way it keeps the ones typed into it. Inputs: TARGET (the project folder) and
# PATHS (one path relative to it per line). Each file that exists is printed
# base64-encoded, from shared/ first: that is where a deployed project keeps
# the real one, and the release only holds a symlink to it.
set -o pipefail
cd -- "$TARGET" 2>/dev/null || { echo "✗ $TARGET isn't on the server"; exit 3; }

limit=65536
found=0

while IFS= read -r rel; do
  [ -n "$rel" ] || continue
  case "$rel" in /*|..|../*|*/..|*/../*) echo "! $rel isn't a path inside the project"; continue ;; esac

  for base in shared current .; do
    file="$base/$rel"
    [ -f "$file" ] || continue

    size=$(wc -c < "$file" | tr -d ' ')
    if [ "$size" -gt "$limit" ]; then
      echo "! $rel is $size bytes, more than an env file should be: left alone"
      break
    fi

    echo "::deployme::file=$rel:$(base64 < "$file" | tr -d '\n')"
    echo "✓ $rel ($size bytes, from $base/)"
    found=$((found + 1))
    break
  done
done <<< "$PATHS"

[ "$found" -gt 0 ] || echo "· Nothing to read: none of those paths is in $TARGET"
echo "::deployme::found=$found"
