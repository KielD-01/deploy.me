# Runs docker compose for the project's stack, from one folder: a release, or
# the project folder. Inputs: DIR, STACK, FILES (compose files inside DIR, one
# per line), ACTION (config, build, pull, recreate, run or exec), SERVICES (one
# per line), COMMAND (for run and exec). Compose's progress goes to stdout.
set -o pipefail
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }
export COMPOSE_ANSI=never BUILDKIT_PROGRESS=plain

# A registry login of this deploy's own (deploy-registry-login.sh).
if [ -n "${DOCKER_CONFIG_DIR:-}" ]; then
  export DOCKER_CONFIG="$DOCKER_CONFIG_DIR"
fi

args=(--ansi never -p "$STACK" --project-directory "$DIR")
while IFS= read -r file; do
  [ -n "$file" ] || continue
  case "$file" in /*|..|../*|*/..|*/../*) echo "✗ $file isn't a path inside the project"; exit 2 ;; esac
  [ -f "$file" ] || { echo "✗ $file isn't in $DIR"; exit 3; }
  args+=(-f "$file")
done <<< "$FILES"

# The release's own image pins (IMAGE_TAG and the like) go over the shared .env.
if [ -f .deployme-pins.env ]; then
  [ -f .env ] && args+=(--env-file "$DIR/.env")
  args+=(--env-file "$DIR/.deployme-pins.env")
fi

services=()
while IFS= read -r service; do
  [ -n "$service" ] && services+=("$service")
done <<< "$SERVICES"

case "$ACTION" in
  config)
    # Everything the stack needs to be readable is in place by now: the files,
    # the env files they read, and the release's image pins.
    echo "\$ docker compose -p $STACK config --quiet"
    if ! out=$(docker compose "${args[@]}" config --quiet 2>&1); then
      printf '%s\n' "$out" | tail -n 5
      echo "✗ Compose can't read the stack from $DIR: $(printf '%s' "$out" | grep -v '^[[:space:]]*$' | tail -n 1)"
      exit 6
    fi
    services=$(docker compose "${args[@]}" config --services 2>/dev/null | grep -c . || true)
    echo "✓ Compose reads the stack from this release: ${services} service(s)"
    echo "::deployme::services=$services"
    ;;
  build)
    echo "\$ docker compose -p $STACK build"
    docker compose "${args[@]}" build 2>&1
    ;;
  pull)
    echo "\$ docker compose -p $STACK pull ${services[*]}"
    docker compose "${args[@]}" pull "${services[@]}" 2>&1
    status=$?
    # The login was for this pull only.
    [ -n "${DOCKER_CONFIG_DIR:-}" ] && rm -rf -- "$DOCKER_CONFIG_DIR"
    exit $status
    ;;
  recreate)
    # An app service's containers, to tell which ones `up` replaces.
    containers() { docker compose "${args[@]}" ps -aq "$1" 2>/dev/null | sort | tr '\n' ' '; }
    before=()
    for service in "${services[@]}"; do before+=("$(containers "$service")"); done

    echo "\$ docker compose -p $STACK up -d"
    docker compose "${args[@]}" up -d 2>&1 || exit $?

    # `up` replaces the ones that changed (a new image tag). The others, as in
    # a redeploy of the same images, are recreated to run this release: each
    # app service restarts once, never twice.
    stale=()
    for i in "${!services[@]}"; do
      [ "$(containers "${services[$i]}")" = "${before[$i]}" ] && stale+=("${services[$i]}")
    done
    status=0
    if [ ${#stale[@]} -gt 0 ]; then
      echo "\$ docker compose -p $STACK up -d --force-recreate --no-deps ${stale[*]}"
      docker compose "${args[@]}" up -d --force-recreate --no-deps "${stale[@]}" 2>&1
      status=$?
    elif [ ${#services[@]} -gt 0 ]; then
      echo "✓ up -d replaced ${services[*]}"
    fi
    [ "$status" -eq 0 ] || exit "$status"

    # The containers of a service this release no longer has, renamed or dropped,
    # would go on running, and the edge on routing to them by their labels. They
    # go now that the release's own are up. One-off containers stay, and so does
    # everything when Compose can't say which services the release has.
    wanted=$(COMPOSE_PROFILES='*' docker compose "${args[@]}" config --services 2>/dev/null)
    if [ -n "$wanted" ]; then
      gone=$(docker ps -a --filter "label=com.docker.compose.project=$STACK" \
          --format '{{.ID}} {{.Label "com.docker.compose.service"}} {{.Label "com.docker.compose.oneoff"}} {{.Names}}' 2>/dev/null \
        | WANTED="$wanted" awk 'BEGIN { n = split(ENVIRON["WANTED"], w, "\n"); for (i = 1; i <= n; i++) keep[w[i]] = 1 }
            NF >= 4 && $3 != "True" && !($2 in keep) { print $1, $4 }')
      if [ -n "$gone" ]; then
        docker stop $(printf '%s\n' "$gone" | cut -d' ' -f1) > /dev/null 2>&1 || true
        docker rm -f $(printf '%s\n' "$gone" | cut -d' ' -f1) > /dev/null 2>&1 || true
        echo "✓ Retired $(printf '%s\n' "$gone" | cut -d' ' -f2 | paste -sd ' ' -): their services aren't in this release"
      fi
    fi
    ;;
  run)
    [ ${#services[@]} -gt 0 ] || { echo "✗ No service to run in"; exit 2; }
    service=${services[0]}
    case "$service" in *[!a-zA-Z0-9._-]*) echo "✗ $service isn't a Compose service name"; exit 2 ;; esac
    # The one-off gets none of the service's labels, or the edge, which routes
    # by label, would send it live requests. A last compose file resets them
    # (`!reset` needs Compose 2.24).
    override=$(mktemp "${TMPDIR:-/tmp}/deployme-run.XXXXXX") || { echo "✗ Couldn't write a temporary file"; exit 3; }
    printf "services:\n  '%s':\n    labels: !reset {}\n    label_file: !reset []\n" "$service" > "$override"
    if [ -n "${COMMAND:-}" ]; then
      echo "\$ docker compose -p $STACK run --rm --no-deps $service $COMMAND"
      docker compose "${args[@]}" -f "$override" run --rm --no-deps -T "$service" sh -c "$COMMAND" 2>&1
    else
      # No command: the service's own (a migrate service that migrates by itself).
      echo "\$ docker compose -p $STACK run --rm --no-deps $service"
      docker compose "${args[@]}" -f "$override" run --rm --no-deps -T "$service" 2>&1
    fi
    status=$?
    rm -f -- "$override"
    exit $status
    ;;
  exec)
    [ ${#services[@]} -gt 0 ] || { echo "✗ No service to run in"; exit 2; }
    echo "\$ docker compose -p $STACK exec ${services[0]} $COMMAND"
    docker compose "${args[@]}" exec -T "${services[0]}" sh -c "$COMMAND" 2>&1
    ;;
  *)
    echo "✗ Unknown compose action: $ACTION"
    exit 2
    ;;
esac
