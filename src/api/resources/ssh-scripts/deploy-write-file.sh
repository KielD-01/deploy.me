# Writes one file deploy.me holds (an env file). Inputs: FILE, CONTENT, MODE.
# The content travels inside this script over stdin, never on a command line,
# and only its path and size reach the log.
set -eo pipefail
umask 077

mkdir -p -- "$(dirname -- "$FILE")"
tmp="$FILE.deployme-$$"
printf '%s' "$CONTENT" > "$tmp"
chmod "$MODE" -- "$tmp"
mv -f -- "$tmp" "$FILE"

echo "✓ $FILE ($(wc -c < "$FILE" | tr -d ' ') bytes, mode $MODE)"
