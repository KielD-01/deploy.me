#!/bin/sh
# deploy.me — trims a PHP base image once its extensions are compiled in, and
# checks what's left. Shared by Dockerfile.base and Dockerfile.base-slim, which
# mount it for the RUN that calls it (it isn't left in the image).
#
#   trim-base.sh purge    the compiler toolchain, the build-only leftovers, and
#                         the PHP binaries deploy.me never runs (php-cgi, phpdbg)
#   trim-base.sh check    after the image is flattened: the same PHP modules as
#                         before the purge, every binary's libraries there, and
#                         the tools deploy.me shells out to run
#
# The official PHP images (and FrankenPHP's, built on them) keep $PHPIZE_DEPS —
# gcc, g++, binutils, autoconf, make, the C headers — so extensions can be
# compiled on top: about 250 MB that nothing runs. install-php-extensions marks
# every package it touched as installed by hand, so `apt-get autoremove` alone
# takes none of it: the packages are named here. Only the ones dpkg says are
# installed are passed to apt (the names differ per architecture, and some
# sanitizer libraries exist on one only). Nothing can be compiled on top of the
# result: the headers and PHP's source tarball go too.
set -eu

modules=/usr/local/etc/deployme-php-modules
environment=/usr/local/etc/deployme-build-env

case "${1:-}" in
purge)
  # Globs, matched against the installed package names. Not gcc-1*: that would
  # take gcc-14-base, which libc6 depends on.
  patterns="$PHPIZE_DEPS gcc-14 gcc-*-linux-gnu binutils* cpp* g++* libc-dev-bin libc6-dev
    linux-libc-dev libcrypt-dev rpcsvc-proto libstdc++-*-dev libgcc-*-dev libasan* libtsan*
    libhwasan* liblsan* libubsan* libitm* libcc1-* libisl* libmpc* libmpfr* m4 patch pkgconf*
    libpkgconf* libdpkg-perl libmagic*"

  php -m > "$modules"
  env | sort > "$environment"

  purge=""
  for package in $(dpkg-query -W -f='${db:Status-Abbrev}${Package}\n' | awk '$1 == "ii" { print $2 }'); do
    for pattern in $patterns; do
      # shellcheck disable=SC2254 # the patterns are globs on purpose
      case "$package" in
        $pattern) purge="$purge $package"; break ;;
      esac
    done
  done

  # shellcheck disable=SC2086 # one word per package
  apt-get purge -y --auto-remove $purge
  rm -rf /var/lib/apt/lists/* /var/cache/apt/* /var/cache/debconf/*-old /var/log/apt/* /var/log/dpkg.log \
    /usr/local/include /usr/src/php.tar.xz /usr/src/php.tar.xz.asc /usr/local/bin/install-php-extensions \
    /usr/local/bin/php-cgi /usr/local/bin/phpdbg \
    /usr/share/doc/* /usr/share/man/* /usr/share/info/* /usr/share/lintian /tmp/pear /root/.cache /root/.pearrc
  ;;

check)
  # The same extensions as before the purge.
  php -m | diff -u "$modules" - || { echo "trim-base: PHP's modules changed in the purge" >&2; exit 1; }
  rm -f "$modules"

  # FROM scratch keeps none of the upstream image's settings: the Dockerfile
  # declares them again. Every variable the build stage had must be here with
  # the same value, but the ones that described PHP's source tarball (gone) and
  # PHP_VERSION, which is the line (8.5) instead of one patch release, so a
  # weekly rebuild on a newer patch doesn't need an edit.
  env | sort > /tmp/trim-env
  bad=0
  while IFS= read -r line; do
    name=${line%%=*}
    case "$name" in
      HOSTNAME | HOME | PWD | SHLVL | _ | OLDPWD | PHP_URL | PHP_ASC_URL | PHP_SHA256 | GPG_KEYS) continue ;;
      PHP_VERSION)
        case "${line#*=}" in
          "$PHP_VERSION".*) continue ;;
        esac
        ;;
    esac
    if ! grep -qxF -- "$line" /tmp/trim-env; then
      echo "trim-base: the image doesn't set $line (Dockerfile: ENV)" >&2
      bad=1
    fi
  done < "$environment"
  rm -f "$environment" /tmp/trim-env
  [ "$bad" = 0 ] || exit 1

  # Every library a binary or an extension links against is still there.
  missing=$(find /usr/local/bin /usr/local/lib /usr/bin /usr/lib/git-core -type f \( -perm -u+x -o -name '*.so*' \) \
    -exec sh -c 'for f; do ldd "$f" 2>/dev/null | sed -n "s|^|$f: |; /not found/p"; done' sh {} + )
  if [ -n "$missing" ]; then
    echo "trim-base: libraries missing after the purge:" >&2
    echo "$missing" >&2
    exit 1
  fi

  # The tools deploy.me runs, and the compiler (and the unused SAPIs) gone.
  git --version
  ssh -V
  ssh-keygen -t ed25519 -N '' -q -f /tmp/trim-key && rm -f /tmp/trim-key /tmp/trim-key.pub
  rsync --version | head -n 1
  tar --version | head -n 1
  zstd --version
  curl --version | head -n 1
  jq --version
  unzip -v | head -n 1
  ps -o pid= -p 1 > /dev/null
  composer --version --no-interaction
  if command -v frankenphp > /dev/null; then frankenphp version; fi
  if [ "${2:-}" = slim ]; then sqlite3 --version; fi
  for gone in gcc cc g++ make ld as autoconf php-cgi phpdbg; do
    if command -v "$gone" > /dev/null; then
      echo "trim-base: $gone is still installed" >&2
      exit 1
    fi
  done
  ;;

*)
  echo "usage: trim-base.sh purge|check [slim]" >&2
  exit 2
  ;;
esac
