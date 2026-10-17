# Waits for the stack's services to be running and, where they have a health
# check, healthy. Inputs: DIR, STACK, FILES (compose files, one per line),
# SERVICES (the ones to wait for, one per line; all of them when empty),
# TIMEOUT and INTERVAL (seconds).
set -o pipefail
cd -- "$DIR" || { echo "✗ $DIR isn't on the server"; exit 3; }

args=(--ansi never -p "$STACK" --project-directory "$DIR")
while IFS= read -r file; do
  [ -n "$file" ] && args+=(-f "$file")
done <<< "$FILES"

# The release's own image pins go over the shared .env (deploy-compose.sh does the same).
if [ -f .deployme-pins.env ]; then
  [ -f .env ] && args+=(--env-file "$DIR/.env")
  args+=(--env-file "$DIR/.deployme-pins.env")
fi

wanted=" "
while IFS= read -r service; do
  [ -n "$service" ] && wanted="$wanted$service "
done <<< "$SERVICES"

declare -A seen
start=$(date +%s)
deadline=$(( start + TIMEOUT ))
echo "Waiting for ${SERVICES:+$(echo $SERVICES) in }stack $STACK (up to $TIMEOUT s)"

while :; do
  waiting=""
  found=0
  # A one-off (a `compose run` left behind without --rm) is listed under its
  # service's name, and an exited one isn't the service being down.
  oneoffs=" $(docker ps -a --filter "label=com.docker.compose.project=$STACK" --filter label=com.docker.compose.oneoff=True --format '{{.Names}}' 2>/dev/null | tr '\n' ' ') "
  while IFS=$'\t' read -r service state health name; do
    [ -n "$service" ] || continue
    [ -z "$name" ] || case "$oneoffs" in *" $name "*) continue ;; esac
    [ "$health" != "-" ] || health=""
    [ "$wanted" = " " ] || case "$wanted" in *" $service "*) ;; *) continue ;; esac
    found=$(( found + 1 ))
    now_status="$state${health:+, $health}"
    if [ "${seen[$service]:-}" != "$now_status" ]; then
      echo "$service $now_status"
      seen[$service]=$now_status
    fi
    case "$state/$health" in
      running/healthy|running/) ;;
      *) waiting="$waiting $service ($now_status)" ;;
    esac
  done < <(docker compose "${args[@]}" ps --all --format '{{.Service}}	{{.State}}	{{if .Health}}{{.Health}}{{else}}-{{end}}	{{.Name}}' 2>/dev/null)

  now=$(date +%s)
  if [ "$found" -gt 0 ] && [ -z "$waiting" ]; then
    echo "✓ Everything is up after $(( now - start )) s"
    echo "::deployme::after=$(( now - start ))"
    exit 0
  fi

  if [ "$now" -ge "$deadline" ]; then
    [ "$found" -gt 0 ] || waiting=" no containers are running"
    echo "✗ After $TIMEOUT s:$waiting"
    echo "::deployme::waiting=${waiting# }"
    exit 30
  fi

  sleep "$INTERVAL"
done
