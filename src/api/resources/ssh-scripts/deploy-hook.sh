# Runs one of the project's hooks, or a step of one of its actions, in a
# release's folder. Inputs: DIR, COMMAND (from the project's settings or the
# action), RELEASE, COMMIT, and ENV_VARS: variables for the command, one
# NAME=<base64 value> per line (an action's inputs), so their values arrive
# over stdin like everything else and never on a command line. The command's
# own exit code is this script's.
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }
while IFS= read -r entry; do
  [ -n "$entry" ] || continue
  name=${entry%%=*}
  case "$name" in ''|[0-9]*|*[!A-Za-z0-9_]*) echo "✗ $name isn't a variable name"; exit 2 ;; esac
  value=$(printf '%s' "${entry#*=}" | base64 -d; printf x)
  export "$name=${value%x}"
done <<< "${ENV_VARS:-}"
unset entry name value
export DEPLOYME_RELEASE="$RELEASE" DEPLOYME_COMMIT="$COMMIT" DEPLOYME_DIR="$DIR"
bash -c "$COMMAND"
