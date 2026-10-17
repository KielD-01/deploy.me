# Waits for the app to answer, asking from the server itself. Inputs: URL,
# TIMEOUT and INTERVAL (seconds). A 2xx or 3xx passes, where redirects lead
# (curl and wget follow them). Uses curl or wget, and
# for plain http:// falls back to bash's /dev/tcp on servers with neither.
#
# With curl it asks this server's own edge first, the URL's host resolved to
# 127.0.0.1, so a CDN in front of the app (Cloudflare's bot challenge, its
# cache) can't answer for it. When nothing answers there, the URL as the world
# sees it.

probe_tcp() {
  case "$URL" in http://*) ;; *) echo none; return ;; esac
  rest=${URL#http://}
  hostport=${rest%%/*}
  if [ "$rest" = "$hostport" ]; then path=/; else path=/${rest#*/}; fi
  host=${hostport%%:*}
  port=${hostport##*:}
  [ "$port" = "$hostport" ] && port=80
  line=$(timeout 10 bash -c 'exec 3<>"/dev/tcp/$1/$2" || exit 1; printf "GET %s HTTP/1.0\r\nHost: %s\r\nConnection: close\r\n\r\n" "$3" "$4" >&3; head -n 1 <&3' _ "$host" "$port" "$path" "$hostport" 2>/dev/null)
  code=$(printf '%s' "$line" | awk '{ print $2 }')
  echo "${code:-000}"
}

# The URL through this server's edge: 000 when nothing answers there. The edge
# may hold an origin certificate no browser trusts, and it's this server anyway.
probe_edge() {
  case "$URL" in
    https://*) rest=${URL#https://}; port=443 ;;
    http://*) rest=${URL#http://}; port=80 ;;
    *) echo 000; return ;;
  esac
  hostport=${rest%%/*}
  host=${hostport%%:*}
  [ "$host" != "$hostport" ] && port=${hostport##*:}
  # Redirects are followed, still through this edge (an http:// URL is sent on
  # to https:// as a rule): the edge's own redirect says nothing about the app.
  curl -sS -k -L --max-redirs 5 -o /dev/null -w '%{http_code}' --max-time 10 \
    --resolve "$host:$port:127.0.0.1" --resolve "$host:443:127.0.0.1" "$URL" 2>/dev/null || true
}

# The HTTP status, then who answered: edge or public.
probe() {
  if command -v curl >/dev/null 2>&1; then
    code=$(probe_edge)
    if [ -n "$code" ] && [ "$code" != 000 ]; then
      echo "$code edge"
      return
    fi
    code=$(curl -sS -L --max-redirs 5 -o /dev/null -w '%{http_code}' --max-time 10 "$URL" 2>/dev/null || true)
  elif command -v wget >/dev/null 2>&1; then
    code=$(wget -q -S -O /dev/null --timeout=10 --tries=1 "$URL" 2>&1 | awk '/^  HTTP\// { code = $2 } END { print code }')
  else
    code=$(probe_tcp)
  fi
  echo "${code:-000} public"
}

start=$(date +%s)
deadline=$(( start + TIMEOUT ))

while :; do
  answer=$(probe)
  code=${answer% *}
  via=${answer##* }
  now=$(date +%s)

  if [ "$code" = none ]; then
    echo "✗ The server has neither curl nor wget to check $URL"
    exit 12
  fi

  case "$code" in
    2??|3??)
      if [ "$via" = edge ]; then where=" (asked this server's edge)"; else where=""; fi
      echo "✓ $URL answered $code after $(( now - start )) s$where"
      echo "::deployme::code=$code"
      echo "::deployme::after=$(( now - start ))"
      echo "::deployme::via=$via"
      exit 0
      ;;
  esac

  if [ -n "$code" ] && [ "$code" != 000 ]; then last="HTTP $code"; else last="no answer"; fi

  if [ "$now" -ge "$deadline" ]; then
    echo "✗ $URL gave $last for $TIMEOUT s"
    echo "::deployme::last=$last"
    exit 30
  fi

  echo "… $last, trying again in $INTERVAL s"
  sleep "$INTERVAL"
done
