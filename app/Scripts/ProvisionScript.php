<?php

namespace App\Scripts;

use App\Models\Server;

/**
 * Turns a fresh Ubuntu 22.04 / 24.04 machine into a Deploi-managed server.
 * Runs as root. Emits ##DEPLOI_PROGRESS## markers for the UI progress bar and
 * ##DEPLOI_DEPLOY_KEY## with the system user's public key at the end.
 */
class ProvisionScript
{
    public function __construct(protected Server $server) {}

    public function render(): string
    {
        $s = $this->server;
        $user = $s->system_user;
        $home = $s->homePath();
        $sudoPassword = Bash::q("{$user}:{$s->sudo_password}");
        $deploiKey = Bash::q(trim($s->public_key));
        $hostname = Bash::q(preg_replace('/[^a-z0-9-]/', '-', strtolower($s->name)));

        $parts = [];

        $parts[] = <<<'BASH'
export NEEDRESTART_MODE=a
APT="apt-get -y -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold"

if [ ! -f /etc/os-release ] || ! grep -q '^ID=ubuntu' /etc/os-release; then
    echo "Deploi supports Ubuntu 22.04 and 24.04 only."
    exit 1
fi

# Fresh cloud machines are often still running cloud-init / unattended-upgrades.
if command -v cloud-init >/dev/null 2>&1; then
    timeout 600 cloud-init status --wait >/dev/null 2>&1 || true
fi

BASH;

        $parts[] = Bash::progress(5, 'Updating system packages');
        $parts[] = <<<BASH
\$APT update
\$APT upgrade
\$APT install software-properties-common curl wget git unzip zip acl ufw fail2ban supervisor cron gnupg \
    ca-certificates jq rsync htop logrotate unattended-upgrades lsb-release psmisc
hostnamectl set-hostname {$hostname} 2>/dev/null || true
timedatectl set-timezone UTC 2>/dev/null || ln -sf /usr/share/zoneinfo/UTC /etc/localtime

BASH;

        $parts[] = Bash::progress(15, 'Configuring swap');
        $parts[] = <<<'BASH'
if [ "$(swapon --show | wc -l)" -eq 0 ] && ! systemd-detect-virt -cq; then
    MEM_MB=$(free -m | awk '/Mem:/{print $2}')
    SWAP_MB=$(( MEM_MB < 2048 ? 2048 : 1024 ))
    fallocate -l ${SWAP_MB}M /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile \
        && echo '/swapfile none swap sw 0 0' >> /etc/fstab \
        && echo 'vm.swappiness=10' > /etc/sysctl.d/99-deploi-swap.conf && sysctl -p /etc/sysctl.d/99-deploi-swap.conf \
        || echo "Could not create swap - skipping."
fi

BASH;

        $parts[] = Bash::progress(20, "Creating the {$user} user");
        $parts[] = <<<BASH
if ! id {$user} >/dev/null 2>&1; then
    useradd -m -s /bin/bash {$user}
fi
echo {$sudoPassword} | chpasswd
usermod -aG sudo {$user}

# Deploi's own key gets access to both root and the deploy user
mkdir -p /root/.ssh {$home}/.ssh {$home}/.deploi/logs {$home}/.letsencrypt
grep -qF {$deploiKey} /root/.ssh/authorized_keys 2>/dev/null || echo {$deploiKey} >> /root/.ssh/authorized_keys
grep -qF {$deploiKey} {$home}/.ssh/authorized_keys 2>/dev/null || echo {$deploiKey} >> {$home}/.ssh/authorized_keys

# The deploy user's own key - added to GitHub/GitLab/Bitbucket so the server can clone repositories
if [ ! -f {$home}/.ssh/id_ed25519 ]; then
    ssh-keygen -t ed25519 -N '' -C "{$user}@\$(hostname)" -f {$home}/.ssh/id_ed25519
fi
ssh-keyscan -H github.com gitlab.com bitbucket.org >> {$home}/.ssh/known_hosts 2>/dev/null || true
sort -u {$home}/.ssh/known_hosts -o {$home}/.ssh/known_hosts

chown -R {$user}:{$user} {$home}
chmod 700 {$home}/.ssh
chmod 600 {$home}/.ssh/authorized_keys {$home}/.ssh/id_ed25519
chmod 755 {$home}

# Let the deploy user reload services from deploy scripts without a password
cat > /etc/sudoers.d/deploi <<'SUDOERS'
{$user} ALL=(root) NOPASSWD: /usr/sbin/service php*-fpm reload, /usr/sbin/service php*-fpm restart, /usr/sbin/service nginx reload, /usr/bin/supervisorctl *
SUDOERS
chmod 440 /etc/sudoers.d/deploi

BASH;

        $parts[] = Bash::progress(25, 'Hardening SSH and enabling the firewall');
        $parts[] = <<<'BASH'
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || true

ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable || echo "UFW could not be enabled on this host - continuing."

systemctl enable --now fail2ban || true

# Automatic security updates
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'APTCONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
APTCONF

BASH;

        if ($s->hasNginx()) {
            $parts[] = Bash::progress(35, 'Installing Nginx');
            $parts[] = $this->nginx();
        }

        if ($s->hasPhp() && $s->php_version) {
            $parts[] = Bash::progress(45, "Installing PHP {$s->php_version}");
            $parts[] = PhpScripts::install($s, $s->php_version);
            $parts[] = PhpScripts::installComposer();
            $parts[] = PhpScripts::setCliDefault($s->php_version);
        }

        if ($s->install_node && $s->type !== 'database') {
            $parts[] = Bash::progress(60, 'Installing Node.js');
            $parts[] = <<<'BASH'
if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    $APT install nodejs
fi
node -v

BASH;
        }

        if ($s->hasDatabase()) {
            $parts[] = Bash::progress(70, 'Installing '.(Server::DATABASES[$s->database_type] ?? $s->database_type));
            $parts[] = ServerScripts::databaseEngine($s, $s->database_type);
        }

        if ($s->install_redis && $s->type !== 'load_balancer') {
            $parts[] = Bash::progress(85, 'Installing Redis');
            $parts[] = <<<'BASH'
$APT install redis-server
sed -i 's/^supervised .*/supervised systemd/' /etc/redis/redis.conf || true
systemctl enable redis-server
systemctl restart redis-server

BASH;
        }

        $parts[] = Bash::progress(92, 'Configuring Supervisor and Certbot');
        $parts[] = <<<'BASH'
systemctl enable supervisor
systemctl start supervisor
$APT install certbot

$APT autoremove
$APT clean

BASH;

        $parts[] = Bash::progress(100, 'Finished');
        $parts[] = "echo \"##DEPLOI_DEPLOY_KEY:\$(cat {$home}/.ssh/id_ed25519.pub)##\"\n";
        $parts[] = "echo 'Server provisioned successfully.'\n";

        return implode('', $parts);
    }

    protected function nginx(): string
    {
        $user = $this->server->system_user;

        $catchAll = <<<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}
NGINX;

        return <<<BASH
\$APT install nginx
sed -i "s/^user .*;/user {$user};/" /etc/nginx/nginx.conf
sed -i "s/# server_tokens off;/server_tokens off;/" /etc/nginx/nginx.conf
sed -i "s/# server_names_hash_bucket_size.*/server_names_hash_bucket_size 128;/" /etc/nginx/nginx.conf
cat > /etc/nginx/conf.d/deploi-gzip.conf <<'GZIP'
gzip_comp_level 5;
gzip_min_length 256;
gzip_proxied any;
gzip_vary on;
gzip_types application/javascript application/json application/xml text/css text/plain text/xml image/svg+xml;
client_max_body_size 64M;
GZIP
rm -f /etc/nginx/sites-enabled/default

BASH
            .Bash::writeFile('/etc/nginx/sites-available/000-catch-all', $catchAll)
            .<<<'BASH'
ln -sf /etc/nginx/sites-available/000-catch-all /etc/nginx/sites-enabled/000-catch-all
nginx -t
systemctl enable nginx
systemctl restart nginx

BASH;
    }
}
