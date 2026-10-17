# Blue/green: starts new containers of each service beside the ones running now,
# waits for the new ones to report healthy, retires the old ones, then names the
# new ones after their colour (warehouse-api-blue-1). Inputs: DIR, STACK, FILES
# (compose files inside DIR, one per line), SERVICES (one per line), TIMEOUT
# (seconds to wait), COLOR (what to call the new set).
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
[ ${#services[@]} -gt 0 ] || { echo "✗ No service to start beside"; exit 2; }

# Every service the release has, profiles included. One it doesn't have (a
# rollback to a release from before a rename) is left to the recreate step,
# which starts whatever the release does have.
defined=$(COMPOSE_PROFILES='*' docker compose "${args[@]}" config --services 2>/dev/null)

# What each service runs now: the colour serving the traffic. A service with
# nothing running yet, just renamed or new, starts at the count its compose file
# asks for, with nothing to retire.
old=(); scale=(); rolled=()
for service in "${services[@]}"; do
  if [ -n "$defined" ] && ! printf '%s\n' "$defined" | grep -qx -- "$service"; then
    echo "! $service isn't in this release's compose file: the recreate step starts what it has"
    continue
  fi
  rolled+=("$service")
  # A stopped container of the service (a replica that crashed, one stopped by
  # hand) counts toward --scale, and --no-recreate would start it again, with
  # the old release's image and config, as the new colour. It goes first.
  stopped=$(docker ps -aq --filter "label=com.docker.compose.project=$STACK" --filter "label=com.docker.compose.service=$service" \
    --filter label=com.docker.compose.oneoff=False --filter status=exited --filter status=created --filter status=dead 2>/dev/null)
  if [ -n "$stopped" ]; then
    # shellcheck disable=SC2086
    docker rm -f $stopped > /dev/null 2>&1 || true
    echo "  $service: removed $(printf '%s\n' "$stopped" | grep -c .) stopped container(s) first"
  fi
  ids=$(docker compose "${args[@]}" ps -q "$service" 2>/dev/null)
  count=$(printf '%s\n' "$ids" | grep -c . || true)
  if [ "$count" -eq 0 ]; then
    echo "  $service: none running yet, so it starts fresh"
    continue
  fi
  for id in $ids; do old+=("$id"); done
  scale+=(--scale "$service=$((count * 2))")
  echo "  $service: $count running"
done

if [ ${#rolled[@]} -eq 0 ]; then
  echo "· Nothing to start beside in this release"
  echo "::deployme::started=0"
  echo "::deployme::rolled="
  exit 0
fi

echo "\$ docker compose -p $STACK up -d --no-deps --no-recreate ${scale[*]} ${rolled[*]}"
docker compose "${args[@]}" up -d --no-deps --no-recreate "${scale[@]}" "${rolled[@]}" 2>&1 || exit $?

# Whatever runs now and didn't before is the new colour, and each new container's
# name to be: warehouse-api-blue for one, warehouse-api-blue-1 and -2 for two.
new=(); renames=()
for service in "${rolled[@]}"; do
  fresh=()
  for id in $(docker compose "${args[@]}" ps -q "$service" 2>/dev/null); do
    case " ${old[*]} " in *" $id "*) ;; *) fresh+=("$id") ;; esac
  done
  i=0
  for id in "${fresh[@]}"; do
    i=$((i + 1))
    new+=("$id")
    if [ ${#fresh[@]} -eq 1 ]; then renames+=("$id $service-${COLOR:-}"); else renames+=("$id $service-${COLOR:-}-$i"); fi
  done
done
[ ${#new[@]} -gt 0 ] || { echo "✗ Compose started no new containers"; exit 5; }
if [ ${#old[@]} -gt 0 ]; then
  echo "✓ ${#new[@]} ${COLOR:-new} containers started beside ${#old[@]} still serving"
else
  echo "✓ ${#new[@]} ${COLOR:-new} containers started"
fi

state() { docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$1" 2>/dev/null || echo gone; }
name() { docker inspect -f '{{.Name}}' "$1" 2>/dev/null | tr -d /; }

waited=0
while :; do
  waiting=()
  for id in "${new[@]}"; do
    status=$(state "$id")
    case "$status" in healthy|running) ;; *) waiting+=("$(name "$id") ($status)") ;; esac
  done
  [ ${#waiting[@]} -eq 0 ] && break

  if [ "$waited" -ge "${TIMEOUT:-180}" ]; then
    echo "✗ After ${waited} s: ${waiting[*]}"
    echo "! Removing the new containers again; the old ones keep serving."
    docker rm -f "${new[@]}" > /dev/null 2>&1
    echo "::deployme::started=0"
    echo "::deployme::waiting=${waiting[*]}"
    exit 6
  fi

  sleep 3
  waited=$((waited + 3))
done

echo "✓ The ${COLOR:-new} containers are healthy after ${waited} s"
if [ ${#old[@]} -gt 0 ]; then
  # Stop first, so each old container gets its own grace period to finish what it
  # is serving; the edge stops sending to it as soon as it goes down.
  docker stop "${old[@]}" > /dev/null 2>&1 || true
  docker rm -f "${old[@]}" > /dev/null 2>&1 || true
  echo "✓ Retired ${#old[@]} old containers"
fi

# Compose's own names (stack-service-25) grow with every roll. Only the name
# changes: Compose finds its containers, and the edge its upstreams, by label.
# A name that can't be taken is left as it was; it never fails the deploy.
if [ -n "${COLOR:-}" ]; then
  for pair in "${renames[@]}"; do
    id=${pair%% *}; target=${pair#* }
    current=$(name "$id")
    [ "$current" = "$target" ] && continue
    if docker rename "$id" "$target" > /dev/null 2>&1; then
      echo "✓ $current is now $target"
    else
      echo "! Couldn't rename $current to $target: the name may be taken"
    fi
  done
fi

echo "::deployme::started=${#new[@]}"
echo "::deployme::retired=${#old[@]}"
echo "::deployme::color=${COLOR:-}"
echo "::deployme::rolled=$(IFS=,; echo "${rolled[*]}")"
