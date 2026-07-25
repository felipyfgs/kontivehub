#!/usr/bin/env bash

set -Eeuo pipefail

readonly APP_DIR=/app
readonly RUNTIME_HOME=/tmp/frontend-home
readonly COREPACK_HOME_DIR=/tmp/corepack
readonly task="${1:-dev}"

if [[ ! "${LOCAL_UID:-}" =~ ^[0-9]+$ ]] || [[ ! "${LOCAL_GID:-}" =~ ^[0-9]+$ ]]; then
    echo "LOCAL_UID e LOCAL_GID devem ser inteiros positivos." >&2
    exit 64
fi

install -d -o "$LOCAL_UID" -g "$LOCAL_GID" \
    "$APP_DIR/node_modules" "$RUNTIME_HOME" "$COREPACK_HOME_DIR"

if [[ "$task" == "prepare" ]]; then
    for path in .nuxt .output test-results playwright-report; do
        if [[ -e "$APP_DIR/$path" ]]; then
            chown -R "$LOCAL_UID:$LOCAL_GID" "$APP_DIR/$path"
        fi
    done
fi

run_as_host_user() {
    setpriv \
        --reuid="$LOCAL_UID" \
        --regid="$LOCAL_GID" \
        --clear-groups \
        env HOME="$RUNTIME_HOME" COREPACK_HOME="$COREPACK_HOME_DIR" \
        "$@"
}

exec_as_host_user() {
    exec setpriv \
        --reuid="$LOCAL_UID" \
        --regid="$LOCAL_GID" \
        --clear-groups \
        env HOME="$RUNTIME_HOME" COREPACK_HOME="$COREPACK_HOME_DIR" \
        "$@"
}

case "$task" in
    prepare)
        exec_as_host_user true
        ;;
    install)
        exec_as_host_user corepack pnpm install --frozen-lockfile
        ;;
    generate)
        run_as_host_user corepack pnpm install --frozen-lockfile
        exec_as_host_user corepack pnpm run generate
        ;;
    dev)
        run_as_host_user corepack pnpm install --frozen-lockfile
        exec_as_host_user corepack pnpm run dev --host 0.0.0.0 --port 3000
        ;;
    *)
        echo "Tarefa frontend inválida: $task" >&2
        exit 64
        ;;
esac
