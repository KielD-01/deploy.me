# Removes the registry login a deploy made for its pull, when the pull never
# got to it. Inputs: DIR (only ever a .deploy/docker-<run> folder).
case "$DIR" in
  */.deploy/docker-*) rm -rf -- "$DIR"; echo "✓ Forgot this deploy's registry login" ;;
  *) echo "! Not a deploy's registry login: $DIR" ;;
esac
