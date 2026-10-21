<?php

namespace App\Scripts;

use App\Models\Server;

class PhpScripts
{
    /** Always installed - PHP can't serve sites without these. */
    public const REQUIRED = ['fpm', 'cli', 'common'];

    /**
     * Installed when the PPA has a build for the version. Not every version has every package:
     * e.g. PHP 8.5+ has OPcache built in (no php8.5-opcache), and brand-new releases often lack
     * PECL extensions like redis/imagick for a few weeks.
     */
    public const EXTENSIONS = [
        'mysql', 'pgsql', 'sqlite3', 'redis', 'gd', 'curl', 'mbstring', 'xml', 'zip',
        'bcmath', 'intl', 'readline', 'soap', 'imagick', 'opcache',
    ];

    /** Install a PHP version from the ondrej/php PPA and configure its FPM pool to run as the system user. */
    public static function install(Server $server, string $version): string
    {
        $user = $server->system_user;
        $required = collect(self::REQUIRED)->map(fn ($ext) => "php{$version}-{$ext}")->implode(' ');
        $optional = implode(' ', self::EXTENSIONS);

        return <<<BASH
if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    \$APT update
fi

PHP_PACKAGES="{$required}"
for EXT in {$optional}; do
    if apt-cache show "php{$version}-\$EXT" >/dev/null 2>&1; then
        PHP_PACKAGES="\$PHP_PACKAGES php{$version}-\$EXT"
    else
        echo "php{$version}-\$EXT is not available for this version - skipping"
    fi
done
\$APT install \$PHP_PACKAGES

# Run FPM as the deploy user so it can read/write the application files
POOL=/etc/php/{$version}/fpm/pool.d/www.conf
sed -i "s/^user = .*/user = {$user}/" \$POOL
sed -i "s/^group = .*/group = {$user}/" \$POOL
sed -i "s/^listen\.owner.*/listen.owner = {$user}/" \$POOL
sed -i "s/^listen\.group.*/listen.group = {$user}/" \$POOL
sed -i "s/^;\?listen\.mode.*/listen.mode = 0666/" \$POOL
sed -i "s/^pm\.max_children.*/pm.max_children = 20/" \$POOL

for INI in /etc/php/{$version}/fpm/php.ini /etc/php/{$version}/cli/php.ini; do
    sed -i "s/^memory_limit = .*/memory_limit = 512M/" \$INI
    sed -i "s/^upload_max_filesize = .*/upload_max_filesize = 64M/" \$INI
    sed -i "s/^post_max_size = .*/post_max_size = 64M/" \$INI
    sed -i "s/^;\?date.timezone.*/date.timezone = UTC/" \$INI
done

systemctl enable php{$version}-fpm
systemctl restart php{$version}-fpm

BASH;
    }

    /** Prints ##DEPLOI_PHP_VERSION:8.4:8.4.2## so PhpUpdated can record the exact patch release. */
    public static function reportVersion(string $version): string
    {
        return "echo \"##DEPLOI_PHP_VERSION:{$version}:\$(php{$version} -r 'echo PHP_VERSION;')##\"\n";
    }

    /** Upgrade an installed PHP version to the newest patch release (e.g. 8.4.1 -> 8.4.2) from the PPA. */
    public static function update(string $version): string
    {
        return <<<BASH
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
APT="apt-get -y -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold"

echo "Currently running PHP \$(php{$version} -r 'echo PHP_VERSION;')"
\$APT update

# Only the php{$version} packages that are actually installed - nothing new gets pulled in.
PACKAGES=\$(dpkg-query -W -f='\${db:Status-Abbrev} \${Package}\\n' 'php{$version}-*' 2>/dev/null | awk '\$1 == "ii" { print \$2 }')
if [ -z "\$PACKAGES" ]; then
    echo "No PHP {$version} packages are installed."
    exit 1
fi

\$APT install --only-upgrade \$PACKAGES
systemctl reload php{$version}-fpm || systemctl restart php{$version}-fpm
echo "Now running PHP \$(php{$version} -r 'echo PHP_VERSION;')"

BASH.self::reportVersion($version);
    }

    public static function uninstall(string $version): string
    {
        return <<<BASH
APT="apt-get -y -o DPkg::Lock::Timeout=600"
systemctl stop php{$version}-fpm || true
\$APT purge 'php{$version}-*'
\$APT autoremove
BASH;
    }

    public static function setCliDefault(string $version): string
    {
        return "update-alternatives --set php /usr/bin/php{$version}\nphp -v\n";
    }

    public static function installComposer(): string
    {
        return <<<'BASH'
if [ ! -f /usr/local/bin/composer ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
/usr/local/bin/composer self-update --2 || true

BASH;
    }

    /** Standalone task: install an additional PHP version on an already-provisioned server. */
    public static function installTask(Server $server, string $version): string
    {
        return "APT=\"apt-get -y -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold\"\n"
            .self::install($server, $version)
            .self::reportVersion($version)
            ."echo 'PHP {$version} installed.'\n";
    }
}
