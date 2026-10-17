# Converts a folder an earlier deployment left in place into releases/ and
# shared/, one phase at a time, and puts it back on undo. Nothing here deletes
# a file: items are renamed, and undo renames them back.
# Inputs: TARGET, RELEASE (the new release's name), MODE, and ITEMS: one
# "destination<TAB>type<TAB>path" line per item that moves, destination
# "release" or "shared". A path with a slash is a shared path inside an item
# that goes to the release. STAYS lists the items left where they are; STACK
# and FILES are the Compose project name and files.
#   check        the folder is as the plan saw it, and can be converted
#   backup       hard links of what moves, in .deploy/convert-RELEASE/old
#   move         creates the release and moves the items, each written down first
#   link         links the shared paths into the release
#   compose      docker compose reads the stack from the release
#   undo         moves everything back, newest first
#   drop-backup  removes .deploy/convert-RELEASE/old
set -o pipefail
umask 022

fail() { echo "✗ $1"; exit "$2"; }

[[ "$RELEASE" =~ ^[0-9]{8}-[0-9]{6}(-[0-9]+)?$ ]] || fail "$RELEASE isn't a release name" 2
cd -- "$TARGET" 2>/dev/null || fail "$TARGET isn't on the server" 3

work=".deploy/convert-$RELEASE"
release="releases/$RELEASE"
inside() { case "$1" in ""|/*|.|..|../*|*/..|*/../*|./*) return 1 ;; esac; return 0; }
kind() { if [ -L "$1" ]; then echo l; elif [ -d "$1" ]; then echo d; else echo f; fi; }
there() { [ -e "$1" ] || [ -L "$1" ]; }
# Written down before it happens, so undo can put back whatever got done.
log_move() { printf '%s\t%s\n' "$1" "$2" >> "$work/moves"; }

case "$MODE" in
  check)
    there "$release" && fail "$release is already there" 5
    there "$work" && fail "$work is already there" 5
    if there current && [ ! -L current ]; then fail "$TARGET/current is a folder, not a link" 4; fi
    { cp --version 2>/dev/null | grep -q GNU && mv --version 2>/dev/null | grep -q GNU; } || fail "Converting needs GNU cp and mv on the server" 6
    dev=$(stat -c %d -- .)
    planned=$'\n'
    n=0
    while IFS=$'\t' read -r to type path; do
      [ -n "$path" ] || continue
      inside "$path" || fail "$path isn't a path inside the folder" 7
      case "$to" in release|shared) ;; *) fail "$path has no destination" 7 ;; esac
      # Inside an item that goes to the release: looked for once it's there.
      case "$path" in */*) continue ;; esac
      there "$path" || fail "$path is gone since the folder was read. Read it again." 8
      [ "$(kind "$path")" = "$type" ] || fail "$path changed since the folder was read. Read it again." 8
      if [ "$type" != l ] && [ "$(stat -c %d -- "$path")" != "$dev" ]; then
        fail "$path is on another filesystem, so it can't move with a rename" 9
      fi
      planned+="$path"$'\n'
      n=$((n + 1))
    done <<< "$ITEMS"
    while IFS= read -r path; do [ -n "$path" ] && planned+="$path"$'\n'; done <<< "$STAYS"
    while IFS= read -r -d '' entry; do
      name=${entry#./}
      case "$name" in .deploy|releases|shared|current|.current.*) continue ;; esac
      case "$planned" in *$'\n'"$name"$'\n'*) ;; *) fail "$name is new since the folder was read. Read it again." 8 ;; esac
    done < <(find . -mindepth 1 -maxdepth 1 -print0)
    echo "✓ The folder is as the plan saw it: $n item(s) to move"
    ;;

  backup)
    mkdir -p -- "$work/old"
    printf '%s\n' "$ITEMS" > "$work/plan"
    n=0
    while IFS=$'\t' read -r to type path; do
      [ -n "$path" ] || continue
      case "$path" in */*) continue ;; esac
      cp -al -- "$path" "$work/old/" || fail "Couldn't back up $path" 10
      n=$((n + 1))
    done <<< "$ITEMS"
    echo "✓ $n item(s) linked into $work/old, nothing copied"
    echo "::deployme::backed_up=$n"
    ;;

  move)
    [ -f "$work/plan" ] || fail "The old layout wasn't backed up, so nothing moves" 11
    mkdir -p -- "$release" shared
    : >> "$work/moves"
    to_release=0
    to_shared=0
    # The folder's own items, into the release or shared/.
    while IFS=$'\t' read -r to type path; do
      [ -n "$path" ] || continue
      case "$path" in */*) continue ;; esac
      if [ "$to" = shared ]; then dest="shared/$path"; else dest="$release/$path"; fi
      there "$dest" && fail "$dest is already there" 12
      log_move "$path" "$dest"
      mv -- "$path" "$dest" || fail "Couldn't move $path" 13
      echo "$path → $dest"
      if [ "$to" = shared ]; then to_shared=$((to_shared + 1)); else to_release=$((to_release + 1)); fi
    done <<< "$ITEMS"
    # Shared paths inside what went to the release: on into shared/.
    while IFS=$'\t' read -r to type path; do
      [ "$to" = shared ] || continue
      case "$path" in */*) ;; *) continue ;; esac
      src="$release/$path"
      there "$src" || { echo "! $path isn't in the release, so it stays out of shared/"; continue; }
      dest="shared/$path"
      there "$dest" && fail "$dest is already there" 12
      mkdir -p -- "$(dirname -- "$dest")"
      log_move "$src" "$dest"
      mv -- "$src" "$dest" || fail "Couldn't move $path" 13
      echo "$src → $dest"
      to_shared=$((to_shared + 1))
    done <<< "$ITEMS"
    echo "✓ $to_release into the release, $to_shared into shared/"
    echo "::deployme::to_release=$to_release"
    echo "::deployme::to_shared=$to_shared"
    ;;

  link)
    n=0
    while IFS=$'\t' read -r to type path; do
      [ "$to" = shared ] && [ -n "$path" ] || continue
      there "shared/$path" || { echo "! shared/$path isn't there, so nothing links to it"; continue; }
      link="$release/$path"
      mkdir -p -- "$(dirname -- "$link")"
      there "$link" && fail "$link is in the way" 14
      log_move link "$link"
      ln -s -- "$TARGET/shared/$path" "$link" || fail "Couldn't link $path" 14
      echo "✓ $path → shared/$path"
      n=$((n + 1))
    done <<< "$ITEMS"
    echo "::deployme::linked=$n"
    ;;

  compose)
    args=()
    while IFS= read -r file; do [ -n "$file" ] && args+=(-f "$file"); done <<< "$FILES"
    dir="$TARGET/$release"
    if ! out=$(cd -- "$dir" && docker compose --ansi never -p "$STACK" --project-directory "$dir" "${args[@]}" config --services 2>&1); then
      fail "Compose can't read the stack from $release: $(printf '%s' "$out" | tail -n 1)" 15
    fi
    services=$(printf '%s\n' "$out" | grep -c .)
    echo "✓ Compose reads the stack from $release: $services service(s)"
    echo "::deployme::services=$services"
    ;;

  undo)
    if [ -L current ] && [ "$(readlink current)" = "$release" ]; then
      rm -f -- current
      echo "✓ current no longer points at $release"
    fi
    [ -f "$work/moves" ] || { echo "✓ Nothing had moved yet"; echo "::deployme::back=0"; exit 0; }
    back=0
    while IFS=$'\t' read -r from to; do
      [ -n "$to" ] || continue
      if [ "$from" = link ]; then
        [ -L "$to" ] && rm -f -- "$to"
        continue
      fi
      # Never moved, or back already.
      there "$from" && continue
      there "$to" || { echo "! $to is missing, so $from can't come back"; continue; }
      mkdir -p -- "$(dirname -- "$from")"
      mv -- "$to" "$from" || fail "Couldn't move $to back to $from" 16
      echo "$to → $from"
      back=$((back + 1))
    done < <(tac -- "$work/moves" 2>/dev/null || sed '1!G;h;$!d' -- "$work/moves")
    if there "$release"; then
      rmdir -- "$release" 2>/dev/null || echo "! $release isn't empty, so it stays"
    fi
    find shared -mindepth 1 -depth -type d -empty -delete 2>/dev/null || true
    mv -f -- "$work/moves" "$work/moves.undone"
    echo "✓ $back item(s) are back where they were"
    echo "::deployme::back=$back"
    ;;

  drop-backup)
    if [ ! -d "$work/old" ]; then
      echo "✓ The backup was gone already"
      exit 0
    fi
    rm -rf -- "$work/old"
    echo "✓ Removed $work/old"
    ;;

  *) fail "Unknown mode $MODE" 2 ;;
esac
