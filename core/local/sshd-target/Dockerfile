# deploy.me — local SSH sandbox: a stand-in server for developing and testing
# the SSH layer without touching real servers. Started by `make sandbox`; it
# appears in the add-server wizard as the alias "sandbox".
#
# Ubuntu 24.04 like the real servers: GNU coreutils, util-linux (lscpu, flock),
# git and rsync. /opt/infra is root-owned (the reserved shared edge path) and
# /opt/srv is writable by `deploy`. Docker is simulated by a shim.

FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends openssh-server rsync git util-linux procps sudo ca-certificates curl \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd --system docker \
 && useradd --create-home --shell /bin/bash --groups docker deploy \
 && install -d -o root -g root -m 0755 /opt/infra \
 && install -d -o deploy -g deploy -m 0775 /opt/srv \
 && install -d -m 0755 /run/sshd

COPY docker /usr/local/bin/docker
COPY sshd_config /etc/ssh/sshd_config.d/deployme.conf
COPY entrypoint.sh /usr/local/bin/sandbox-entrypoint
RUN chmod 0755 /usr/local/bin/docker /usr/local/bin/sandbox-entrypoint

EXPOSE 22

ENTRYPOINT ["sandbox-entrypoint"]
