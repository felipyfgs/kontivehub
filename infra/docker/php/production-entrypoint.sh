#!/bin/sh
set -eu

readonly_runtime_secret=/run/secrets/api_runtime_env

if [ ! -r "$readonly_runtime_secret" ]; then
    echo "Docker Secret api_runtime_env não foi montado." >&2
    exit 78
fi

set -a
# O secret é um arquivo POSIX com pares CHAVE=valor e permissões controladas pelo Swarm.
. "$readonly_runtime_secret"
set +a

if [ "${APP_ENV:-}" != "production" ] || [ "${APP_DEBUG:-true}" != "false" ]; then
    echo "A imagem de produção exige APP_ENV=production e APP_DEBUG=false." >&2
    exit 78
fi

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

if [ ! -f /var/www/html/bootstrap/cache/packages.php ]; then
    gosu www-data php artisan package:discover --ansi --no-interaction
fi

if [ "$#" -eq 0 ] || [ "$1" = "php-fpm" ]; then
    if [ "$#" -eq 0 ]; then
        set -- php-fpm
    fi
    exec docker-php-entrypoint "$@"
fi

exec gosu www-data docker-php-entrypoint "$@"
