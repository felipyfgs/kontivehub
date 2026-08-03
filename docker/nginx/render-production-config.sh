#!/bin/sh
set -eu

readonly_template=/etc/nginx/kontivehub.conf.template
readonly_config=/etc/nginx/conf.d/default.conf

edge_token=${NGINX_EDGE_TOKEN:-}
if ! printf '%s' "$edge_token" | grep -Eq '^[a-f0-9]{64}$'; then
    echo "NGINX_EDGE_TOKEN deve conter 64 caracteres hexadecimais minúsculos." >&2
    exit 78
fi
case "$edge_token" in
    0000000000000000000000000000000000000000000000000000000000000000)
        echo "NGINX_EDGE_TOKEN ainda contém o valor de exemplo." >&2
        exit 78
        ;;
esac

cleanup_temporary_config() {
    rm -f "$temporary_config"
}

umask 077
temporary_config=$(mktemp "${readonly_config}.tmp.XXXXXX")
trap cleanup_temporary_config EXIT
trap 'cleanup_temporary_config; exit 130' INT
trap 'cleanup_temporary_config; exit 143' TERM

sed "s/__KONTIVEHUB_EDGE_TOKEN__/$edge_token/g" "$readonly_template" > "$temporary_config"
chmod 0600 "$temporary_config"
mv -f "$temporary_config" "$readonly_config"
trap - EXIT INT TERM
