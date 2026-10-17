# Reads a folder an earlier deployment left in place, before converting it to
# releases. Changes nothing. Inputs: TARGET, STACK (may be empty), FILES
# (compose files, one per line), PATHS (paths inside the folder to look for,
# one per line), ONLY_PATHS (1: just look for PATHS). Besides ✓/! lines and
# ::deployme:: markers it prints tab-separated records:
#   __ITEM__     type bytes files mtime same-filesystem name   (the folder's own items)
#   __PATH__     type bytes path                               (the PATHS that exist)
#   __TRACKED__  name                                          (a git checkout's tracked items)
#   __BIND__     container source destination                  (bind mounts from inside the folder)
#   __VOLUME__   name                                          (named volumes the stack uses)
#   __CRON__     a crontab line or a cron/systemd file         (scheduled jobs that use the folder)
# and each compose file between "__COMPOSE__ name" and "__COMPOSE_END__".
set -o pipefail
cd -- "$TARGET" 2>/dev/null || { echo "✗ $TARGET isn't on the server"; exit 3; }

inside() { case "$1" in ""|/*|.|..|../*|*/..|*/../*) return 1 ;; esac; return 0; }
kind() { if [ -L "$1" ]; then echo l; elif [ -d "$1" ]; then echo d; else echo f; fi; }
size() {
  local bytes kb
  bytes=$(du -sb -- "$1" 2>/dev/null | cut -f1)
  if [ -z "$bytes" ]; then
    kb=$(du -sk -- "$1" 2>/dev/null | cut -f1)
    bytes=$(( ${kb:-0} * 1024 ))
  fi
  echo "${bytes:-0}"
}

while IFS= read -r path; do
  inside "$path" || continue
  { [ -e "$path" ] || [ -L "$path" ]; } || continue
  printf '__PATH__\t%s\t%s\t%s\n' "$(kind "$path")" "$(size "$path")" "$path"
done <<< "$PATHS"

[ "${ONLY_PATHS:-0}" = 1 ] && exit 0

dev=$(stat -c %d -- . 2>/dev/null || echo 0)
count=0
while IFS= read -r -d '' entry; do
  name=${entry#./}
  case "$name" in .deploy|releases|shared|current|.current.*) continue ;; esac
  case "$name" in *$'\t'*|*$'\n'*) echo "! Left out an item whose name has a tab or a line break"; continue ;; esac
  type=$(kind "$name")
  files=1
  [ "$type" = d ] && files=$(find "$name" -type f 2>/dev/null | wc -l | tr -d ' ')
  same=1
  if [ "$type" != l ] && [ "$(stat -c %d -- "$name" 2>/dev/null)" != "$dev" ]; then same=0; fi
  printf '__ITEM__\t%s\t%s\t%s\t%s\t%s\t%s\n' "$type" "$(size "$name")" "$files" "$(stat -c %Y -- "$name" 2>/dev/null || echo 0)" "$same" "$name"
  count=$((count + 1))
done < <(find . -mindepth 1 -maxdepth 1 -print0 2>/dev/null | sort -z)
echo "✓ $count item(s) in $TARGET"
echo "::deployme::items=$count"

# Reading only: git mustn't even refresh its index here.
export GIT_OPTIONAL_LOCKS=0
if [ -e .git ] && command -v git >/dev/null 2>&1 && sha=$(git -c safe.directory='*' rev-parse HEAD 2>/dev/null); then
  echo "✓ A git checkout at ${sha:0:7}"
  echo "::deployme::git_commit=$sha"
  echo "::deployme::git_dirty=$(git -c safe.directory='*' status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
  git -c safe.directory='*' ls-files -z 2>/dev/null | tr '\0' '\n' | cut -d/ -f1 | sort -u | while IFS= read -r tracked; do
    [ -n "$tracked" ] && printf '__TRACKED__\t%s\n' "$tracked"
  done
fi

# The image tag a Compose deploy pinned: the commit the stack runs.
if [ -f .env ]; then
  tag=$(sed -n 's/^IMAGE_TAG=//p' .env 2>/dev/null | tail -n 1 | tr -d "\"' \r")
  case "$tag" in ''|*[!A-Za-z0-9._-]*) ;; *) echo "::deployme::image_tag=$tag" ;; esac
fi

while IFS= read -r file; do
  inside "$file" && [ -f "$file" ] || continue
  if [ "$(stat -c %s -- "$file" 2>/dev/null || echo 0)" -gt 262144 ]; then
    echo "! $file is too big to read"
    continue
  fi
  printf '__COMPOSE__\t%s\n' "$file"
  cat -- "$file"
  printf '\n__COMPOSE_END__\n'
done <<< "$FILES"

if [ -n "$STACK" ] && command -v docker >/dev/null 2>&1; then
  ids=$(docker ps -q --filter "label=com.docker.compose.project=$STACK" 2>/dev/null | tr '\n' ' ')
  if [ -n "${ids// /}" ]; then
    # shellcheck disable=SC2086
    echo "::deployme::containers=$(printf '%s\n' $ids | wc -l | tr -d ' ')"
    # shellcheck disable=SC2086
    echo "::deployme::working_dir=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' $(printf '%s\n' $ids | head -n 1) 2>/dev/null)"
    # Mount sources, never their contents: named volumes, and binds from inside the folder.
    # shellcheck disable=SC2086
    docker inspect -f '{{$n := .Name}}{{range .Mounts}}{{$n}}{{"\t"}}{{.Type}}{{"\t"}}{{.Name}}{{"\t"}}{{.Source}}{{"\t"}}{{.Destination}}{{"\n"}}{{end}}' $ids 2>/dev/null \
      | awk -F '\t' -v root="$TARGET" 'NF >= 5 {
          if ($2 == "volume" && $3 != "") print "__VOLUME__\t" $3
          else if ($2 == "bind" && index($4 "/", root "/") == 1) print "__BIND__\t" substr($1, 2) "\t" (length($4) == length(root) ? "." : substr($4, length(root) + 2)) "\t" $5
        }' | sort -u
  else
    echo "::deployme::containers=0"
  fi

  if [ -n "$FILES" ] && docker compose version >/dev/null 2>&1; then
    args=()
    while IFS= read -r file; do [ -n "$file" ] && args+=(-f "$file"); done <<< "$FILES"
    if err=$(docker compose --ansi never -p "$STACK" --project-directory "$TARGET" "${args[@]}" config --quiet 2>&1); then
      echo "✓ Compose reads the stack's files from $TARGET"
      echo "::deployme::compose_ok=1"
    else
      echo "::deployme::compose_ok=0"
      echo "::deployme::compose_error=$(printf '%s' "$err" | tail -n 1 | tr -d '\r' | cut -c1-240)"
    fi
  fi
fi

# Scheduled jobs that run from this folder: once it's converted, they belong in current/.
crontab -l 2>/dev/null | grep -F -- "$TARGET" | grep -v '^[[:space:]]*#' | while IFS= read -r line; do
  printf '__CRON__\t%s\n' "$(printf '%s' "$line" | cut -c1-240)"
done
for file in /etc/crontab /etc/cron.d/* /etc/systemd/system/*.service /etc/systemd/system/*.timer; do
  if [ -f "$file" ] && grep -qF -- "$TARGET" "$file" 2>/dev/null; then printf '__CRON__\t%s\n' "$file"; fi
done

[ -d .deploy/lock ] && echo "::deployme::locked=$(cut -d' ' -f1 .deploy/lock/owner 2>/dev/null)"
if cp --version 2>/dev/null | grep -q GNU && mv --version 2>/dev/null | grep -q GNU; then echo "::deployme::gnu=1"; else echo "::deployme::gnu=0"; fi
if command -v rsync >/dev/null 2>&1; then echo "::deployme::rsync=1"; else echo "::deployme::rsync=0"; fi
if [ -e current ] && [ ! -L current ]; then echo "::deployme::current_dir=1"; fi
echo "::deployme::ok=1"
