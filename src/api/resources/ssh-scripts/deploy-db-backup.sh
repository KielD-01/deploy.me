# Dumps the project's Postgres database before migrations run. Inputs:
# CONTAINER (the Postgres container), NETWORKS (the stack's, to find it by), DATABASE, DB_USER (the role to connect
# as), DB_PASSWORD (empty for a role the container lets in over its own
# socket), DIR (where the dumps go), KEEP (how many to keep). The password
# reaches pg_dump through the environment, never a command line.
set -eo pipefail
umask 077

# The dump is "$DIR/$DATABASE-<stamp>.dump" and the old ones are found by
# "$DATABASE-*.dump": a name with a slash, a dot in front or a glob character
# would write elsewhere or remove another project's dumps.
case "$DATABASE" in
  ''|*/*|.*|-*|*[!A-Za-z0-9_-]*)
    echo "✗ \"$DATABASE\" isn't a database name a dump can be named after"
    exit 2 ;;
esac

# CONTAINER comes from the project's DB_HOST, which names a NETWORK ALIAS, and
# `docker exec` only takes a name. On this server the shared database answers to
# `postgres` on proxy-network while its container is infra-postgres-1, so look
# the alias up instead of failing. Only on the networks the project's stack joins
# (NETWORKS, one per line), when it names them: another project's own database
# answers to `postgres` too, on that project's private network
# (school-app-postgres-1), and the first alias found used to be the wrong one.
if ! docker inspect --type container "$CONTAINER" > /dev/null 2>&1; then
  matches=()
  elsewhere=()
  while IFS= read -r name; do
    [ -n "$name" ] || continue
    pairs=$(docker inspect -f '{{range $network, $conf := .NetworkSettings.Networks}}{{range $conf.Aliases}}{{$network}} {{.}}{{println}}{{end}}{{end}}' "$name" 2>/dev/null || true)
    while IFS=' ' read -r network alias; do
      [ "$alias" = "$CONTAINER" ] || continue
      if [ -z "${NETWORKS:-}" ] || printf '%s\n' "$NETWORKS" | grep -qxF -- "$network"; then
        matches+=("$name")
      else
        elsewhere+=("$name on $network")
      fi
    done <<< "$pairs"
  done < <(docker ps --format '{{.Names}}')

  mapfile -t matches < <(printf '%s\n' "${matches[@]}" | awk 'NF && !seen[$0]++')

  if [ ${#matches[@]} -eq 0 ]; then
    echo "✗ No running container is named or aliased $CONTAINER${NETWORKS:+ on $(printf '%s' "$NETWORKS" | paste -sd, -)}"
    [ ${#elsewhere[@]} -gt 0 ] && echo "! It's only an alias elsewhere: ${elsewhere[*]}"
    exit 42
  fi
  if [ ${#matches[@]} -gt 1 ]; then
    echo "✗ More than one container answers to $CONTAINER: ${matches[*]}"
    exit 43
  fi
  echo "· $CONTAINER is the container ${matches[0]}"
  CONTAINER=${matches[0]}
fi

mkdir -p -- "$DIR"

file="$DIR/$DATABASE-$(date -u +%Y%m%d-%H%M%S).dump"
export PGPASSWORD="$DB_PASSWORD"
problem=$(mktemp "${TMPDIR:-/tmp}/deployme-dump.XXXXXX")

# No -i: this script itself arrives on stdin, and docker would read the rest
# of it. pg_dump reads nothing there anyway, and -w keeps it from asking for a
# password it can't be given.
if ! docker exec -e PGPASSWORD "$CONTAINER" pg_dump -Fc -w -U "$DB_USER" "$DATABASE" > "$file.tmp" 2>"$problem"; then
  rm -f -- "$file.tmp"
  sed 's/^/  /' < "$problem" >&2

  # pg_dump reads with row_security off, and Postgres refuses that to a role
  # its policies apply to. Only another role gets past it.
  if grep -qiE 'row.level security|row_security' < "$problem"; then
    rm -f -- "$problem"
    echo "✗ pg_dump can't read $DATABASE as $DB_USER: row-level security policies apply to that role"
    echo "! Back it up as a role the policies don't apply to: the table owner, a role with BYPASSRLS,"
    echo "! or the $CONTAINER container's superuser. That's the project's “Back up as” setting."
    exit 41
  fi

  rm -f -- "$problem"
  echo "✗ pg_dump of $DATABASE in the $CONTAINER container failed"
  exit 40
fi

rm -f -- "$problem"
mv -f -- "$file.tmp" "$file"
size=$(wc -c < "$file" | tr -d ' ')
echo "✓ $(basename -- "$file") ($size bytes)"
echo "::deployme::file=$(basename -- "$file")"
echo "::deployme::size=$size"

ls -1t -- "$DIR/$DATABASE"-*.dump 2>/dev/null | tail -n +$(( KEEP + 1 )) | while IFS= read -r old; do
  rm -f -- "$old"
  echo "· Removed $(basename -- "$old")"
done
