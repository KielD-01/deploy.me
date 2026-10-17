# Pre-flight checks before touching a target. Inputs: TARGET, NEED_DOCKER (0/1),
# STACK, MIN_FREE (bytes), VERSIONABLE (0/1), NETWORKS (external networks the
# stack joins, one per line). Prints ✓/✗ lines for the log, and
# ::deployme::key=value markers for deploy.me.
set -eo pipefail

fail() { echo "✗ $1"; exit "$2"; }

# The nearest folder that exists: that's where the target gets created.
dir="$TARGET"
while [ ! -e "$dir" ]; do dir=$(dirname -- "$dir"); done
[ -d "$dir" ] || fail "$dir exists and isn't a folder" 10
[ -w "$dir" ] || fail "$dir isn't writable for $(id -un)" 11
echo "✓ $dir is writable for $(id -un)"
echo "::deployme::user=$(id -un)"

# A folder that already holds a deployment deploy.me didn't set up: say so,
# and leave its files alone. With releases, anything besides deploy.me's own
# folders counts, whatever state.json says.
if [ -d "$TARGET" ] && { [ "${VERSIONABLE:-0}" = 1 ] || [ ! -f "$TARGET/.deploy/state.json" ]; }; then
  others=$(find "$TARGET" -mindepth 1 -maxdepth 1 ! -name .deploy ! -name releases ! -name shared ! -name current -printf '%f\n' 2>/dev/null | sort)
  if [ -n "$others" ]; then
    count=$(printf '%s\n' "$others" | wc -l | tr -d ' ')
    echo "! $TARGET already holds $count item(s) deploy.me didn't put there; they're left as they are"
    echo "::deployme::foreign=$(printf '%s\n' "$others" | head -5 | tr '\n' '|')"
    echo "::deployme::foreign_count=$count"
  fi
fi

free=$(df -P -B1 -- "$dir" 2>/dev/null | awk 'NR == 2 { print $4 }')
echo "::deployme::free=${free:-0}"
if [ -n "$free" ] && [ "$free" -lt "$MIN_FREE" ]; then
  fail "Only $(( free / 1048576 )) MB free on $dir" 13
fi
echo "✓ $(( ${free:-0} / 1073741824 )) GB free"

for tool in tar flock mkdir mv; do
  command -v "$tool" >/dev/null 2>&1 || fail "$tool isn't installed" 12
done
if [ "${NEED_RSYNC:-0}" = 1 ]; then
  command -v rsync >/dev/null 2>&1 || fail "rsync isn't installed on the server, and deploys upload with it" 16
fi
if mv --version 2>/dev/null | grep -q GNU; then
  echo "::deployme::gnu_mv=1"
else
  echo "::deployme::gnu_mv=0"
  echo "! mv isn't GNU mv, so release switches can't be atomic"
fi

if [ "$NEED_DOCKER" = 1 ]; then
  docker_version=$(docker version --format '{{.Server.Version}}' 2>/dev/null) || fail "Docker isn't running, or $(id -un) can't use it" 14
  compose_version=$(docker compose version --short 2>/dev/null) || fail "Docker Compose v2 isn't installed" 15
  echo "✓ Docker $docker_version · Compose $compose_version"
  echo "::deployme::docker=$docker_version"
  echo "::deployme::compose=$compose_version"

  # Compose creates the networks it owns, but never an external one: `up`
  # stops with "network not found" when the server hasn't got it.
  while IFS= read -r network; do
    [ -n "$network" ] || continue
    if docker network inspect "$network" --format '{{.Driver}}' >/dev/null 2>&1; then
      echo "✓ Network $network is on this server"
    else
      fail "The stack joins the external network $network, which isn't on this server. Create it (docker network create $network), or take it out of the compose file." 19
    fi
  done <<< "${NETWORKS:-}"

  # Another Compose project already running from this folder means the stack
  # name changed: its volumes would be orphaned.
  docker compose ls --all --format json 2>/dev/null | tr -d '[]' | sed 's/},{/}\n{/g' | while IFS= read -r entry; do
    name=$(printf '%s' "$entry" | sed -n 's/.*"Name":"\([^"]*\)".*/\1/p')
    files=$(printf '%s' "$entry" | sed -n 's/.*"ConfigFiles":"\([^"]*\)".*/\1/p')
    case ",$files" in
      *",$TARGET/"*)
        if [ -n "$name" ] && [ "$name" != "$STACK" ]; then
          echo "! Compose project $name already runs from $TARGET, not $STACK"
          echo "::deployme::drift=$name"
        fi
        ;;
    esac
  done
fi

echo "::deployme::ok=1"
