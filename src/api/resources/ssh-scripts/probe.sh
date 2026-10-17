# Server probe: CPU, memory, disks, OS, tools and access, and the busiest
# processes, as one JSON object between sentinels (a noisy shell profile can't
# corrupt it). POSIX sh. NET_SAMPLER holds probe-net.sh, which runs as root.
export LC_ALL=C PATH="$PATH:/usr/sbin:/sbin:/usr/local/bin"

js() { printf '"%s"' "$(printf '%s' "$1" | tr '\t\r\n' '   ' | sed 's/\\/\\\\/g; s/"/\\"/g')"; }
num() { case "$1" in ''|*[!0-9.]*) printf null ;; *) printf '%s' "$1" ;; esac; }
yes_no() { if "$@" >/dev/null 2>&1; then printf true; else printf false; fi; }
T() { if command -v timeout >/dev/null 2>&1; then timeout 5 "$@"; else "$@"; fi; }
TL() { if command -v timeout >/dev/null 2>&1; then timeout 20 "$@"; else "$@"; fi; }

work=$(mktemp -d 2>/dev/null) || { work="/tmp/deployme-probe-$$"; mkdir -p "$work"; }
trap 'rm -rf "$work"' EXIT
: > "$work/net"

# ── One second of samples ────────────────────────────────────────────────────
# Machine CPU usage from /proc/stat, and in the same second the busiest
# processes. The network half needs root: the kernel doesn't count traffic per
# process, so probe-net.sh reads every TCP socket's counters in each network
# namespace. It runs once the CPU second is over, under `sudo -n` (never a
# password prompt), and is skipped with a note where it can't run. Entering
# every namespace twice keeps about a core busy for seconds on a host with a
# few dozen containers, so running it inside the CPU second made the machine
# look far busier than it was.
window=1
net_note=""
net_as=""
if [ -z "${NET_SAMPLER:-}" ]; then
  net_note="the network sampler wasn't sent"
elif ! command -v ss >/dev/null 2>&1; then
  net_note="ss isn't installed (iproute2)"
elif ! command -v nsenter >/dev/null 2>&1; then
  net_note="nsenter isn't installed (util-linux)"
elif [ "$(id -u)" = 0 ]; then
  net_as=root
elif sudo -n true >/dev/null 2>&1; then
  net_as=sudo
else
  net_note="needs passwordless sudo"
fi

# Every process's CPU ticks (utime + stime), resident pages and name, from
# /proc/<pid>/stat, which any user can read. The name is the kernel's short one
# (comm), never the command line, which can hold another program's secrets.
proc_sample() {
  cat /proc/[0-9]*/stat 2>/dev/null | awk '
    match($0, /\(.*\)/) {
      name = substr($0, RSTART + 1, RLENGTH - 2); gsub(/[^A-Za-z0-9._:@+\/-]/, "_", name)
      if (split(substr($0, RSTART + RLENGTH + 1), f, " ") >= 22) printf "%s %.0f %.0f %s %s\n", $1, f[12] + f[13], f[22], (name == "" ? "?" : name), f[2]
    }'
}
cpu_sample() { awk '/^cpu /{ t = 0; for (i = 2; i <= 9; i++) t += $i; print t, $5 + $6; exit }' /proc/stat; }
uptime_now() { cut -d' ' -f1 /proc/uptime; }

# The machine's figure covers only the sleep, so reading every process's stat
# around it doesn't count either.
u1=$(uptime_now)
proc_sample > "$work/a"
set -- $(cpu_sample); t1=$1; i1=$2
sleep "$window"
set -- $(cpu_sample)
usage=$(awk -v t=$(($1 - t1)) -v i=$(($2 - i1)) 'BEGIN { if (t > 0) printf "%.1f", (t - i) * 100 / t }')
proc_sample > "$work/b"
u2=$(uptime_now)
seconds=$(awk -v a="$u1" -v b="$u2" 'BEGIN { printf "%.2f", (b > a) ? b - a : 1 }')

case "$net_as" in
  root) printf '%s\n' "$NET_SAMPLER" | TL sh -s "$window" > "$work/net" 2>/dev/null ;;
  sudo) printf '%s\n' "$NET_SAMPLER" | TL sudo -n sh -s "$window" > "$work/net" 2>/dev/null ;;
esac

# Ten by CPU over the window (100 = one core, the way top counts it) and ten by
# resident memory. A process only the second sample has started inside it. The
# probe itself is left out: this shell and everything it started, the cat and
# awk reading every process's stat among them.
hz=$(getconf CLK_TCK 2>/dev/null || echo 100)
page=$(getconf PAGESIZE 2>/dev/null || echo 4096)
awk -v hz="$hz" -v s="$seconds" -v self="$$" '
  FILENAME == ARGV[1] { was[$1] = $2; next }
  { ticks[$1] = $2; name[$1] = $4; parent[$1] = $5 }
  END {
    mine[self] = 1
    for (round = 0; round < 10; round++) for (p in parent) if (parent[p] in mine) mine[p] = 1
    for (p in ticks) if (!(p in mine)) { pct = (ticks[p] - ((p in was) ? was[p] : 0)) * 100 / hz / s; if (pct >= 0.1) printf "%.1f %s %s\n", pct, p, name[p] }
  }' "$work/a" "$work/b" \
  | sort -k1,1 -rn | head -n 10 > "$work/cpu"
awk -v page="$page" -v self="$$" '
  { rss[$1] = $3; name[$1] = $4; parent[$1] = $5 }
  END {
    mine[self] = 1
    for (round = 0; round < 10; round++) for (p in parent) if (parent[p] in mine) mine[p] = 1
    for (p in rss) if (!(p in mine) && rss[p] > 0) printf "%.0f %s %s\n", rss[p] * page, p, name[p]
  }' "$work/b" | sort -k1,1 -rn | head -n 10 > "$work/mem"

if [ -z "$net_note" ] && ! grep -qx ok "$work/net"; then
  net_note=$(sed -n 's/^note //p' "$work/net" | head -n 1)
  [ -n "$net_note" ] || net_note="the network sample didn't finish"
fi

# The container each listed process runs in, from its cgroup.
docker_ids=$(T docker ps --no-trunc --format '{{.ID}} {{.Names}}' 2>/dev/null)
awk '$1 != "note" && $1 != "ok" && NF > 1 { print $2 }' "$work/cpu" "$work/mem" "$work/net" | sort -u | while read -r pid; do
  id=$(sed -n 's/.*docker[-/]\([0-9a-f]\{64\}\).*/\1/p' "/proc/$pid/cgroup" 2>/dev/null | head -n 1)
  [ -n "$id" ] && printf '%s\n' "$docker_ids" | awk -v id="$id" -v pid="$pid" '$1 == id { print pid, $2; exit }'
done > "$work/containers"

top=$(awk -v note="$net_note" -v window="$seconds" '
  function row(pid, name, readings) { return sprintf("{\"pid\":%s,\"name\":\"%s\",\"container\":\"%s\",%s}", pid, name, container[pid], readings) }
  FILENAME == ARGV[1] { container[$1] = $2; next }
  FILENAME == ARGV[2] { cpu = cpu (cpu == "" ? "" : ",") row($2, $3, "\"cpu\":" $1); next }
  FILENAME == ARGV[3] { mem = mem (mem == "" ? "" : ",") row($2, $3, "\"rss\":" $1); next }
  $1 == "net" && NF >= 5 { net = net (net == "" ? "" : ",") row($2, $5, "\"tx\":" $3 ",\"rx\":" $4) }
  END { printf "{\"window\":%s,\"cpu\":[%s],\"mem\":[%s],\"net\":[%s],\"net_note\":%s}", window, cpu, mem, net, (note == "" ? "null" : "\"" note "\"") }
' "$work/containers" "$work/cpu" "$work/mem" "$work/net")

lc() { lscpu 2>/dev/null | awk -F: -v k="$1" '$1 == k { sub(/^[ \t]+/, "", $2); print $2; exit }'; }
threads=$(nproc 2>/dev/null || getconf _NPROCESSORS_ONLN)
cores=$(lscpu -p=SOCKET,CORE 2>/dev/null | grep -v '^#' | sort -u | wc -l | tr -d ' ')
[ "${cores:-0}" -gt 0 ] 2>/dev/null || cores=$threads
model=$(lc 'Model name')
[ -n "$model" ] || model=$(awk -F: '/^model name/ { sub(/^[ \t]+/, "", $2); print $2; exit }' /proc/cpuinfo)
mhz=$(awk -F: '/^cpu MHz/ { s += $2; n++ } END { if (n) printf "%.0f", s / n }' /proc/cpuinfo)
[ -n "$mhz" ] || mhz=$(cat /sys/devices/system/cpu/cpu*/cpufreq/scaling_cur_freq 2>/dev/null | awk '{ s += $1; n++ } END { if (n) printf "%.0f", s / n / 1000 }')
mhz_max=$(lc 'CPU max MHz' | cut -d. -f1)

mem() { awk -v k="$1:" '$1 == k { printf "%.0f", $2 * 1024; exit }' /proc/meminfo; }

# Real filesystems only: no pseudo filesystems, no Docker container layers
# (overlay unless it's /), no bind-mounted files or host shares.
disks=$(df -B1 --output=source,fstype,size,used,avail,target -x tmpfs -x devtmpfs -x squashfs -x efivarfs -x fakeowner -x virtiofs -x 9p 2>/dev/null \
  | awk 'NR > 1 && !($2 == "overlay" && $6 != "/") && $6 !~ /^\/(boot|snap|run|dev|sys|proc|etc)(\/|$)/ { printf "%s{\"source\":\"%s\",\"fstype\":\"%s\",\"size\":%s,\"used\":%s,\"available\":%s,\"mount\":\"%s\"}", (n++ ? "," : ""), $1, $2, $3, $4, $5, $6 }')

osr() { ( . /etc/os-release 2>/dev/null; eval "printf '%s' \"\${$1:-}\"" ); }

dk_client=$(T docker version --format '{{.Client.Version}}' 2>/dev/null)
dk_server=$(T docker version --format '{{.Server.Version}}' 2>/dev/null)
dk_error=""
[ -n "$dk_server" ] || dk_error=$(T docker info 2>&1 >/dev/null | tail -n 1)
compose=$(T docker compose version --short 2>/dev/null || T docker-compose version --short 2>/dev/null)
proxy_net=$(T docker network inspect proxy-network --format '{{.Driver}}' 2>/dev/null)
cdp=$(T docker ps --format '{{.Names}} {{.Image}}' 2>/dev/null | awk 'tolower($2) ~ /caddy-docker-proxy/ { print $1; exit }')
cdp_on_net=false
[ -n "$cdp" ] && T docker inspect -f '{{json .NetworkSettings.Networks}}' "$cdp" 2>/dev/null | grep -q '"proxy-network"' && cdp_on_net=true
stacks=$(T docker compose ls --all --format json 2>/dev/null)
case "$stacks" in \[*) ;; *) stacks='[]' ;; esac

echo "__DEPLOYME_PROBE_BEGIN__"
printf '{"v":1,"hostname":%s,"user":%s,"home":%s,"arch":%s,"kernel":%s,' \
  "$(js "$(hostname 2>/dev/null)")" "$(js "$(id -un)")" "$(js "$HOME")" "$(js "$(uname -m)")" "$(js "$(uname -r)")"
printf '"os":{"id":%s,"version":%s,"pretty":%s},"uptime":%s,' \
  "$(js "$(osr ID)")" "$(js "$(osr VERSION_ID)")" "$(js "$(osr PRETTY_NAME)")" "$(num "$(cut -d. -f1 /proc/uptime 2>/dev/null)")"
printf '"cpu":{"model":%s,"cores":%s,"threads":%s,"usage":%s,"mhz":%s,"mhz_max":%s,"load":%s},' \
  "$(js "$model")" "$(num "$cores")" "$(num "$threads")" "$(num "$usage")" "$(num "$mhz")" "$(num "$mhz_max")" \
  "$(awk '{ printf "[%s,%s,%s]", $1, $2, $3 }' /proc/loadavg)"
printf '"mem":{"total":%s,"available":%s,"swap_total":%s,"swap_free":%s},"disks":[%s],' \
  "$(num "$(mem MemTotal)")" "$(num "$(mem MemAvailable)")" "$(num "$(mem SwapTotal)")" "$(num "$(mem SwapFree)")" "$disks"
printf '"tools":{"bash":%s,"git":%s,"rsync":%s,"docker_client":%s,"docker_server":%s,"docker_error":%s,"compose":%s,"gnu_mv":%s,"flock":%s},' \
  "$(js "$(command -v bash)")" "$(js "$(git --version 2>/dev/null | awk '{ print $3 }')")" \
  "$(js "$(rsync --version 2>/dev/null | awk 'NR == 1 { print $3 }')")" "$(js "$dk_client")" "$(js "$dk_server")" \
  "$(js "$dk_error")" "$(js "$compose")" "$(yes_no sh -c 'mv --version | grep -q "GNU coreutils"')" "$(yes_no command -v flock)"
printf '"proxy":{"network":%s,"caddy_docker_proxy":%s,"caddy_on_network":%s},' "$(js "$proxy_net")" "$(js "$cdp")" "$cdp_on_net"
printf '"access":{"uid":%s,"sudo_nopasswd":%s,"docker_group":%s},' "$(id -u)" "$(yes_no sudo -n true)" "$(yes_no sh -c 'id -nG | grep -qw docker')"
printf '"top":%s,' "$top"
printf '"stacks":%s}\n' "$stacks"
echo "__DEPLOYME_PROBE_END__"
