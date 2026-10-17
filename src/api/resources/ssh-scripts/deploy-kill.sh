# Stops what a deploy script left running on the server once deploy.me let
# go of it (a cancel, a timeout): the script's whole process group, which it
# wrote to FILE as it started. Inputs: FILE (.deploy/runs/<run>.pgid).
# Nothing left to stop is fine.
case "$FILE" in */.deploy/runs/*.pgid) ;; *) echo "✗ $FILE isn't a run's process file"; exit 2 ;; esac

# A script cut off as it started may still be about to write it.
for _ in 1 2 3 4; do [ -f "$FILE" ] && break; sleep 0.5; done

pgid=""
[ -f "$FILE" ] && read -r pgid < "$FILE" 2>/dev/null
case "$pgid" in ''|*[!0-9]*|0|1) rm -f -- "$FILE"; echo "· Nothing of it was still running"; exit 0 ;; esac

alive() { kill -0 -- "-$pgid" 2>/dev/null; }

if ! alive; then
  rm -f -- "$FILE"
  echo "· Nothing of it was still running"
  exit 0
fi

kill -TERM -- "-$pgid" 2>/dev/null
for _ in 1 2 3 4 5 6 7 8 9 10; do
  alive || break
  sleep 0.5
done

if alive; then
  kill -KILL -- "-$pgid" 2>/dev/null
  echo "✓ Killed what it left running on the server"
else
  echo "✓ Stopped what it left running on the server"
fi
rm -f -- "$FILE"
exit 0
