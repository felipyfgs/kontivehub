#!/bin/sh
set -eu

readonly_runtime_secret=/run/secrets/wazync_runtime_env

if [ ! -r "$readonly_runtime_secret" ]; then
    echo "Docker Secret wazync_runtime_env não foi montado." >&2
    exit 78
fi

# Parser data-only incorporado à imagem; o conteúdo do secret nunca é executado.
. /usr/local/lib/load-env-secret.sh
load_env_secret "$readonly_runtime_secret"

exec "$@"
