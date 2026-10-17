# Logs the server's docker in to a registry for one deploy: the credentials
# go into DIR, a docker config of the deploy's own that the pull removes.
# Inputs: REGISTRY, USERNAME, TOKEN (inside this script, never on a command
# line), DIR.
set -o pipefail
umask 077
mkdir -p -- "$DIR"

if out=$(printf '%s' "$TOKEN" | DOCKER_CONFIG="$DIR" docker login "$REGISTRY" -u "$USERNAME" --password-stdin 2>&1); then
  echo "✓ Logged in to $REGISTRY as $USERNAME"
else
  rm -rf -- "$DIR"
  printf '%s\n' "$out" | tail -n 3
  echo "✗ $REGISTRY refused the login for $USERNAME"
  exit 41
fi
