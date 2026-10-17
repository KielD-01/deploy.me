# The network half of the probe's busiest-process lists, run as root: probe.sh
# starts it under `sudo -n`. The kernel doesn't count traffic per process, so
# this reads the byte counters of every TCP socket at both ends of the window,
# in each network namespace (the host's and every container's), and finds the
# process holding each socket through /proc/<pid>/fd. It changes nothing.
#
# Argument: the window in seconds. Prints up to ten lines "net PID TX RX NAME",
# bytes per second each way, busiest first, then "ok". UDP isn't counted, and
# neither is a connection that opens and closes inside the window.
w=${1:-1}
d=$(mktemp -d 2>/dev/null) || { echo "note couldn't make a temporary folder"; exit 0; }
trap 'rm -rf "$d"' EXIT

# One process in each network namespace.
stat -L -c '%i %n' /proc/[0-9]*/ns/net 2>/dev/null | awk '!seen[$1]++ { split($2, p, "/"); print p[3] }' > "$d/ns"

# "INODE TX RX" for every TCP socket. ss -e puts a socket's inode on its first
# line, and -i its counters on the indented line after it.
sample() {
    while read -r pid; do
        nsenter --net="/proc/$pid/ns/net" ss -tinHe < /dev/null 2>/dev/null
    done < "$d/ns" | awk '
        /^[^ \t]/ { ino = ""; if (match($0, /ino:[0-9]+/)) ino = substr($0, RSTART + 4, RLENGTH - 4); next }
        ino != "" {
            tx = 0; rx = 0
            if (match($0, /bytes_sent:[0-9]+/)) tx = substr($0, RSTART + 11, RLENGTH - 11)
            else if (match($0, /bytes_acked:[0-9]+/)) tx = substr($0, RSTART + 12, RLENGTH - 12)
            if (match($0, /bytes_received:[0-9]+/)) rx = substr($0, RSTART + 15, RLENGTH - 15)
            print ino, tx, rx
            ino = ""
        }'
}

up() { cut -d' ' -f1 /proc/uptime; }

u1=$(up)
sample > "$d/a"
sleep "$w"
sample > "$d/b"
u2=$(up)

# The process holding each socket: the first one found, when a fork shares it.
find /proc/[0-9]*/fd -lname 'socket:*' -printf '%h %l\n' 2>/dev/null \
    | awk '{ split($1, p, "/"); ino = $2; gsub(/[^0-9]/, "", ino); if (!(ino in seen)) { seen[ino] = 1; print ino, p[3] } }' > "$d/owners"

# A socket only the second sample has opened inside the window, so all it
# carried counts; one that went backwards was replaced under the same inode.
awk -v u1="$u1" -v u2="$u2" '
    FILENAME == ARGV[1] { owner[$1] = $2; next }
    FILENAME == ARGV[2] { tx0[$1] = $2; rx0[$1] = $3; next }
    ($1 in owner) {
        t = $2 - (($1 in tx0) ? tx0[$1] : 0); if (t < 0) t = 0
        r = $3 - (($1 in rx0) ? rx0[$1] : 0); if (r < 0) r = 0
        tx[owner[$1]] += t; rx[owner[$1]] += r
    }
    END {
        dt = u2 - u1; if (dt <= 0) dt = 1
        for (p in tx) if (tx[p] + rx[p] > 0) printf "%.0f %s %.1f %.1f\n", tx[p] + rx[p], p, tx[p] / dt, rx[p] / dt
    }' "$d/owners" "$d/a" "$d/b" \
    | sort -k1,1 -rn | head -n 10 \
    | while read -r total pid tx rx; do
        name=$(tr -c 'A-Za-z0-9._:@+/\n-' '_' < "/proc/$pid/comm" 2>/dev/null)
        echo "net $pid $tx $rx ${name:-?}"
    done

echo ok
