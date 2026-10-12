#!/bin/sh
set -e

# Only the "app" container (ROLE=app) performs one-time bootstrapping.
if [ "${ROLE:-app}" = "app" ]; then
    if [ ! -f vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    if [ ! -f .env ]; then
        cp .env.docker .env
    fi

    if ! grep -q '^APP_KEY=base64' .env; then
        php artisan key:generate --force
    fi

    # Wait for MySQL, then migrate.
    until php artisan db:show >/dev/null 2>&1; do
        echo "Waiting for database..."
        sleep 2
    done
    php artisan migrate --force
    php artisan storage:link >/dev/null 2>&1 || true

    chown -R www-data:www-data storage bootstrap/cache || true
else
    # Workers wait for the app container to finish installing dependencies / migrating.
    until [ -f vendor/autoload.php ] && php artisan migrate:status >/dev/null 2>&1; do
        echo "Waiting for app to be ready..."
        sleep 3
    done
fi

exec "$@"
