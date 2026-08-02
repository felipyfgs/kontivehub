#!/bin/sh
set -eu

readonly_edge_secret=/run/secrets/nginx_edge_token
readonly_template=/etc/nginx/kontivehub.conf.template
readonly_config=/etc/nginx/conf.d/default.conf

if [ ! -r "$readonly_edge_secret" ]; then
    echo "Docker Secret nginx_edge_token não foi montado." >&2
    exit 78
fi

edge_token=$(tr -d '\r\n' < "$readonly_edge_secret")
if ! printf '%s' "$edge_token" | grep -Eq '^[a-f0-9]{64}$'; then
    echo "Docker Secret nginx_edge_token deve conter 64 caracteres hexadecimais minúsculos." >&2
    exit 78
fi
case "$edge_token" in
    SUBSTITUA*)
        echo "Docker Secret nginx_edge_token ainda contém placeholder." >&2
        exit 78
        ;;
esac

umask 077
sed "s/__KONTIVEHUB_EDGE_TOKEN__/$edge_token/g" "$readonly_template" > "$readonly_config"
