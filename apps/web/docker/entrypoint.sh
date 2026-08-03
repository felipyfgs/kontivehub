#!/usr/bin/env bash

set -Eeuo pipefail

readonly APP_DIR=/app
readonly RUNTIME_HOME=/tmp/frontend-home
readonly COREPACK_HOME_DIR=/tmp/corepack
readonly task="${1:-dev}"

if [[ ! "${LOCAL_UID:-}" =~ ^[1-9][0-9]*$ ]] || [[ ! "${LOCAL_GID:-}" =~ ^[1-9][0-9]*$ ]]; then
    echo "LOCAL_UID e LOCAL_GID devem ser inteiros maiores que zero." >&2
    exit 64
fi

install -d -o "$LOCAL_UID" -g "$LOCAL_GID" \
    "$APP_DIR/node_modules" "$APP_DIR/node_modules/.cache" \
    "$RUNTIME_HOME" "$COREPACK_HOME_DIR"

readonly ownership_marker="$APP_DIR/node_modules/.kontivehub-ownership"
readonly expected_ownership="$LOCAL_UID:$LOCAL_GID"
marker_value=""
marker_ownership=""
if [[ -r "$ownership_marker" ]]; then
    IFS= read -r marker_value < "$ownership_marker" || true
    marker_ownership=$(stat -c '%u:%g' "$ownership_marker" 2>/dev/null || true)
fi

# Volumes anteriores não possuem marcador ou registram outro UID/GID. O
# marcador é gravado somente depois do chown completo, evitando um scan O(n)
# em todo start sem mascarar uma correção interrompida.
if [[ "$marker_value" != "$expected_ownership" || "$marker_ownership" != "$expected_ownership" ]]; then
    chown -R "$LOCAL_UID:$LOCAL_GID" "$APP_DIR/node_modules"
    printf '%s\n' "$expected_ownership" > "$ownership_marker"
    chown "$LOCAL_UID:$LOCAL_GID" "$ownership_marker"
    chmod 0644 "$ownership_marker"
fi

# Esses caminhos são artefatos ignorados e podem ter sido criados por uma
# imagem/UID anterior. Dev, generate e test-gate precisam reescrevê-los.
for path in .nuxt .output test-results playwright-report; do
    if [[ -e "$APP_DIR/$path" ]]; then
        chown -R "$LOCAL_UID:$LOCAL_GID" "$APP_DIR/$path"
    fi
done

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
    test-gate)
        exec_as_host_user corepack pnpm run test:gate
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
