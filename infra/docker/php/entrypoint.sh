#!/bin/sh
set -eu

if [ "$(id -u)" = "0" ]; then
    mkdir -p \
        /var/www/html/storage/app/private \
        /var/www/html/storage/framework/cache \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
        /var/vault

    chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
        /var/vault

    if [ "${APP_ENV:-}" = "production" ] && [ ! -f /var/www/html/bootstrap/cache/packages.php ]; then
        gosu www-data php artisan package:discover --ansi --no-interaction
    fi

    # php-fpm must master as root (pool drops to www-data). Default when no CMD.
    if [ "$#" -eq 0 ] || [ "$1" = "php-fpm" ]; then
        if [ "$#" -eq 0 ]; then
            set -- php-fpm
        fi
        exec docker-php-entrypoint "$@"
    fi

    # artisan / horizon / scheduler / one-off commands as www-data
    exec gosu www-data docker-php-entrypoint "$@"
fi

exec docker-php-entrypoint "$@"
