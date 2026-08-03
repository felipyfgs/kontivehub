#!/bin/sh
set -eu

if [ "${APP_ENV:-local}" = "production" ] && [ "${APP_DEBUG:-false}" != "false" ]; then
    echo "A imagem de produção exige APP_DEBUG=false." >&2
    exit 78
fi

if [ "$(id -u)" = "0" ]; then
    target_uid=${LOCAL_UID:-$(id -u www-data)}
    target_gid=${LOCAL_GID:-$(id -g www-data)}

    case "$target_uid:$target_gid" in
        *[!0-9:]*|:*|*:)
            echo "LOCAL_UID e LOCAL_GID devem ser inteiros positivos." >&2
            exit 64
            ;;
    esac

    if [ "$target_uid" -eq 0 ] || [ "$target_gid" -eq 0 ]; then
        echo "LOCAL_UID e LOCAL_GID devem ser maiores que zero." >&2
        exit 64
    fi
    if [ "$(id -g www-data)" != "$target_gid" ]; then
        groupmod -o -g "$target_gid" www-data
    fi
    if [ "$(id -u www-data)" != "$target_uid" ]; then
        usermod -o -u "$target_uid" -g "$target_gid" www-data
    fi

    mkdir -p \
        /var/www/html/storage/app/private \
        /var/www/html/storage/framework/cache \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
        /var/vault \
        /tmp/composer

    chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
        /var/vault \
        /tmp/composer

    if [ "${APP_ENV:-local}" != "production" ] && [ ! -f /var/www/html/vendor/autoload.php ]; then
        gosu www-data composer install --no-interaction --prefer-dist
    fi

    if [ ! -f /var/www/html/bootstrap/cache/packages.php ]; then
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
