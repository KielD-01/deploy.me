# Runs one of the project's hooks in a release's folder. Inputs: DIR,
# COMMAND (from the project's settings), RELEASE, COMMIT. The hook's own exit
# code is this script's.
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }
export DEPLOYME_RELEASE="$RELEASE" DEPLOYME_COMMIT="$COMMIT" DEPLOYME_DIR="$DIR"
bash -c "$COMMAND"
