<?php

namespace App\Scripts;

use App\Models\Certificate;
use App\Models\Site;

/** Renders a site's Nginx vhost. */
class NginxConfig
{
    public static function render(Site $site, ?Certificate $certificate = null): string
    {
        $domains = implode(' ', $site->allDomains());
        $root = $site->rootPath();
        $home = '/home/'.$site->system_user;
        $log = "/var/log/nginx/{$site->domain}";

        $redirects = $site->redirects->map(
            fn ($r) => "    location = {$r->from} {\n        return {$r->type} {$r->to};\n    }\n"
        )->implode('');

        $app = match ($site->project_type) {
            'nodejs' => self::proxy($site->app_port ?: 3000),
            'static' => self::static(),
            default => self::php($site->phpVersion()),
        };

        $listen = "    listen 80;\n    listen [::]:80;\n";
        $ssl = '';
        $httpRedirect = '';

        if ($certificate) {
            $path = $certificate->path();
            $listen = "    listen 443 ssl http2;\n    listen [::]:443 ssl http2;\n";
            $ssl = <<<NGINX

    ssl_certificate {$path}/fullchain.pem;
    ssl_certificate_key {$path}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    add_header Strict-Transport-Security "max-age=31536000" always;

NGINX;

            $httpRedirect = <<<NGINX

server {
    listen 80;
    listen [::]:80;
    server_name {$domains};

    location ^~ /.well-known/acme-challenge/ {
        root {$home}/.letsencrypt;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

NGINX;
        }

        return <<<NGINX
# Managed by Deploi - changes made here will be overwritten.
server {
{$listen}    server_name {$domains};
    root {$root};
{$ssl}
    index index.php index.html index.htm;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    access_log off;
    error_log {$log}-error.log error;

    location ^~ /.well-known/acme-challenge/ {
        root {$home}/.letsencrypt;
    }

{$redirects}{$app}
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
{$httpRedirect}
NGINX;
    }

    protected static function php(string $version): string
    {
        return <<<NGINX
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php{$version}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

NGINX;
    }

    protected static function static(): string
    {
        return <<<'NGINX'
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(?:css|js|woff2?|svg|png|jpe?g|gif|ico|webp)$ {
        expires 30d;
        access_log off;
    }

NGINX;
    }

    protected static function proxy(int $port): string
    {
        return <<<NGINX
    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
    }

NGINX;
    }
}
