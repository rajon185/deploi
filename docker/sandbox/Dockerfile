# A disposable Ubuntu 24.04 "server" with systemd + sshd, so you can add it in
# Deploi as a Custom server and run the real provisioning script locally.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive container=docker

RUN apt-get update && apt-get install -y --no-install-recommends \
        systemd systemd-sysv openssh-server sudo curl ca-certificates gnupg lsb-release \
        software-properties-common cron iproute2 \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && rm -f /lib/systemd/system/multi-user.target.wants/getty* /usr/sbin/policy-rc.d \
    && systemctl enable ssh \
    && mkdir -p /root/.ssh && chmod 700 /root/.ssh \
    && sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
