<?php

namespace App\Scripts;

use App\Models\Server;

class PhpScripts
{
    public const EXTENSIONS = [
        'fpm', 'cli', 'common', 'mysql', 'pgsql', 'sqlite3', 'redis', 'gd', 'curl', 'mbstring', 'xml', 'zip',
        'bcmath', 'intl', 'readline', 'soap', 'imagick', 'opcache',
    ];

    /** Install a PHP version from the ondrej/php PPA and configure its FPM pool to run as the system user. */
    public static function install(Server $server, string $version): string
    {
        $user = $server->system_user;
        $packages = collect(self::EXTENSIONS)
            ->map(fn ($ext) => "php{$version}-{$ext}")
            ->implode(' ');

        return <<<BASH
if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    \$APT update
fi

\$APT install {$packages}

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
            ."echo 'PHP {$version} installed.'\n";
    }
}
