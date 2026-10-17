# Records the target's state in .deploy/state.json, so the server describes
# itself. Inputs: TARGET, STATE (JSON), ONLY_IF_MISSING (0/1).
set -eo pipefail

file="$TARGET/.deploy/state.json"

if [ "$ONLY_IF_MISSING" = 1 ] && [ -f "$file" ]; then
  echo "✓ Kept the existing .deploy/state.json"
  exit 0
fi

printf '%s\n' "$STATE" > "$file.tmp"
mv -f -- "$file.tmp" "$file"
echo "✓ Wrote .deploy/state.json"
