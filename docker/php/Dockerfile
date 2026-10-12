FROM php:8.4-fpm-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libzip-dev libicu-dev libgmp-dev libonig-dev openssh-client \
    && docker-php-ext-install pdo_mysql zip intl gmp bcmath pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-deploi.ini
COPY docker/php/entrypoint.sh /usr/local/bin/deploi-entrypoint

RUN chmod +x /usr/local/bin/deploi-entrypoint \
    && groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data

WORKDIR /var/www/html

ENTRYPOINT ["deploi-entrypoint"]
CMD ["php-fpm"]
