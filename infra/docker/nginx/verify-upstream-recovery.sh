#!/bin/sh

set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
repository_root=$(CDPATH= cd -- "$script_dir/../../.." && pwd)
timeout_seconds=${NGINX_RECOVERY_TIMEOUT_SECONDS:-60}
holder_name="kontivehub-upstream-address-holder-$$"
holder_created=0
php_recovery_required=0

case "$timeout_seconds" in
    ''|*[!0-9]*)
        echo "NGINX_RECOVERY_TIMEOUT_SECONDS deve ser um inteiro positivo." >&2
        exit 2
        ;;
esac

if [ "$timeout_seconds" -lt 15 ]; then
    echo "NGINX_RECOVERY_TIMEOUT_SECONDS deve ser pelo menos 15." >&2
    exit 2
fi

cd "$repository_root"

compose()
{
    docker compose "$@"
}

validate_configuration()
{
    configuration=$1

    if ! grep -Fq 'resolver 127.0.0.11 valid=10s ipv6=off;' "$configuration" \
        || ! grep -Fq 'set $php_upstream php:9000;' "$configuration" \
        || ! grep -Fq 'fastcgi_pass $php_upstream;' "$configuration" \
        || grep -Fq 'fastcgi_pass php:9000;' "$configuration"; then
        echo "Política de resolução dinâmica ausente em $configuration." >&2
        return 1
    fi

    docker run --rm \
        --entrypoint nginx \
        -v "$repository_root/$configuration:/etc/nginx/conf.d/default.conf:ro" \
        nginx:1.27-alpine \
        -t >/dev/null
}

container_id()
{
    compose ps -q "$1"
}

container_ip()
{
    docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$1"
}

container_network()
{
    docker inspect --format '{{range $name, $settings := .NetworkSettings.Networks}}{{$name}}{{end}}' "$1"
}

nginx_reaches_application()
{
    compose exec -T nginx wget -q -T 3 -O /dev/null http://127.0.0.1/up
}

restore_runtime()
{
    status=$?
    trap - EXIT INT TERM

    if [ "$holder_created" -eq 1 ]; then
        docker rm -f "$holder_name" >/dev/null 2>&1 || true
    fi

    if [ "$php_recovery_required" -eq 1 ]; then
        compose up -d --no-deps php >/dev/null 2>&1 || true
    fi

    exit "$status"
}

trap restore_runtime EXIT INT TERM

validate_configuration infra/docker/nginx/conf/default.conf

nginx_id=$(container_id nginx)
php_id=$(container_id php)

if [ -z "$nginx_id" ] || [ -z "$php_id" ]; then
    echo "Os serviços nginx e php precisam estar em execução." >&2
    exit 1
fi

if ! nginx_reaches_application; then
    echo "A verificação exige /up saudável antes da recriação." >&2
    exit 1
fi

old_php_ip=$(container_ip "$php_id")
app_network=$(container_network "$php_id")
nginx_image=$(docker inspect --format '{{.Config.Image}}' "$nginx_id")

if [ -z "$old_php_ip" ] || [ -z "$app_network" ] || [ -z "$nginx_image" ]; then
    echo "Não foi possível resolver o estado interno dos containers." >&2
    exit 1
fi

printf 'nginx=%s php_anterior=%s\n' \
    "$(printf '%s' "$nginx_id" | cut -c1-12)" \
    "$(printf '%s' "$php_id" | cut -c1-12)"

php_recovery_required=1
compose stop -t 10 php >/dev/null

if nginx_reaches_application >/dev/null 2>&1; then
    echo "/up permaneceu saudável sem PHP; a verificação deve falhar fechada." >&2
    exit 1
fi

compose rm -f php >/dev/null
docker run -d \
    --name "$holder_name" \
    --network "$app_network" \
    --ip "$old_php_ip" \
    --entrypoint sleep \
    "$nginx_image" \
    120 >/dev/null
holder_created=1

compose up -d --no-deps php >/dev/null
new_php_id=$(container_id php)
if [ -z "$new_php_id" ]; then
    echo "A recriação não produziu um endereço PHP diferente." >&2
    exit 1
fi
new_php_ip=$(container_ip "$new_php_id")

if [ -z "$new_php_ip" ] || [ "$new_php_ip" = "$old_php_ip" ]; then
    echo "A recriação não produziu um endereço PHP diferente." >&2
    exit 1
fi

docker rm -f "$holder_name" >/dev/null
holder_created=0
php_recovery_required=0

deadline=$(( $(date +%s) + timeout_seconds ))
while :; do
    current_nginx_id=$(container_id nginx)
    nginx_health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$current_nginx_id")

    if [ "$current_nginx_id" = "$nginx_id" ] \
        && [ "$nginx_health" = "healthy" ] \
        && nginx_reaches_application >/dev/null 2>&1; then
        break
    fi

    if [ "$(date +%s)" -ge "$deadline" ]; then
        echo "Nginx não convergiu para o PHP atual dentro do timeout." >&2
        compose ps nginx php >&2
        exit 1
    fi

    sleep 2
done

printf 'php_atual=%s ip_alterado=sim nginx_preservado=sim health=healthy\n' \
    "$(printf '%s' "$new_php_id" | cut -c1-12)"
