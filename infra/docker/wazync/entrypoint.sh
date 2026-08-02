#!/bin/sh
set -eu

readonly_runtime_secret=/run/secrets/wazync_runtime_env

if [ ! -r "$readonly_runtime_secret" ]; then
    echo "Docker Secret wazync_runtime_env não foi montado." >&2
    exit 78
fi

set -a
# O secret é um arquivo POSIX com pares CHAVE=valor.
. "$readonly_runtime_secret"
set +a

exec "$@"
