#!/usr/bin/env bash

set -Eeuo pipefail

readonly repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly compose_file="$repository_root/docker-compose.test.yml"
readonly project_name="${KONTIVEHUB_TEST_PROJECT:-kontivehub-test-$(id -u)-$$}"

if [[ ! "$project_name" =~ ^kontivehub-test-[a-z0-9][a-z0-9_-]*$ ]]; then
    echo "KONTIVEHUB_TEST_PROJECT deve usar o namespace exclusivo kontivehub-test-*." >&2
    exit 64
fi

compose=(docker compose --project-name "$project_name" --file "$compose_file")

readonly existing_resource_id="$({
    docker ps --all --quiet --filter "label=com.docker.compose.project=$project_name"
    docker network ls --quiet --filter "label=com.docker.compose.project=$project_name"
    docker volume ls --quiet --filter "label=com.docker.compose.project=$project_name"
} | sed -n '1p')"
if [[ -n "$existing_resource_id" ]]; then
    echo "O projeto de teste $project_name já possui recursos; escolha um nome novo." >&2
    exit 73
fi

cleanup() {
    "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

cd "$repository_root"

"${compose[@]}" config --quiet
"${compose[@]}" build api-test web-test wazync-test
"${compose[@]}" up --detach --wait --wait-timeout 180 postgres-test redis nats

status=0
for service in api-test web-test wazync-test; do
    if ! "${compose[@]}" run --rm --no-deps "$service"; then
        status=1
    fi
done

exit "$status"
