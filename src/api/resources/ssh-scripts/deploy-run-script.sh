# Runs the project's own deploy script from the folder the release is in.
# Inputs: DIR, SCRIPT (a path inside the project), RELEASE, COMMIT, TARGET, and
# DOCKER_CONFIG_DIR (the deploy's registry login, if it made one). The script
# gets the release's image pins (.deployme-pins.env: IMAGE_TAG=… and the like)
# in its environment, the way `IMAGE_TAG=<sha> ./deploy.sh` expects them.
# The script's exit code is this one's.
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }
case "$SCRIPT" in ""|/*|..|../*|*/..|*/../*) echo "✗ $SCRIPT isn't a path inside the project"; exit 2 ;; esac
[ -f "$SCRIPT" ] || { echo "✗ $SCRIPT isn't in $DIR"; exit 3; }

export DEPLOYME_RELEASE="$RELEASE" DEPLOYME_COMMIT="$COMMIT" DEPLOYME_TARGET="$TARGET" DEPLOYME_DIR="$DIR"

# NAME=value lines deploy.me wrote; anything else is skipped, nothing is evaluated.
if [ -f .deployme-pins.env ]; then
  while IFS='=' read -r key value; do
    case "$key" in ""|[0-9]*|*[!A-Za-z0-9_]*) continue ;; esac
    export "$key=$value"
    echo "$key=$value"
  done < .deployme-pins.env
fi

if [ -n "${DOCKER_CONFIG_DIR:-}" ]; then
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"
fi

echo "\$ bash $SCRIPT"
bash "$SCRIPT"
