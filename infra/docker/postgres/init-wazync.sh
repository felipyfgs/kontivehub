#!/bin/sh
set -eu

: "${POSTGRES_HOST:=postgres}"
: "${POSTGRES_PORT:=5432}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"
: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is required}"
: "${WAZYNC_DB_USER:?WAZYNC_DB_USER is required}"
: "${WAZYNC_DB_PASSWORD:?WAZYNC_DB_PASSWORD is required}"

if [ "$WAZYNC_DB_USER" = "$POSTGRES_USER" ]; then
    echo "WAZYNC_DB_USER deve ser diferente de POSTGRES_USER" >&2
    exit 2
fi

case "$WAZYNC_DB_USER" in
    *[!a-zA-Z0-9_]*)
        echo "WAZYNC_DB_USER contém caracteres inválidos" >&2
        exit 2
        ;;
esac

export PGPASSWORD="$POSTGRES_PASSWORD"

max_attempts=60
attempt=0
until pg_isready -q -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "Timeout aguardando PostgreSQL ficar disponível." >&2
        exit 1
    fi
    sleep 1
done

psql --quiet --no-psqlrc --set=ON_ERROR_STOP=1 \
    --host="$POSTGRES_HOST" \
    --port="$POSTGRES_PORT" \
    --username="$POSTGRES_USER" \
    --dbname="$POSTGRES_DB" \
    --set=wazync_user="$WAZYNC_DB_USER" \
    --set=wazync_password="$WAZYNC_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'wazync_user', :'wazync_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'wazync_user')
\gexec
SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'wazync_user', :'wazync_password')
\gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS wazync AUTHORIZATION %I', :'wazync_user')
\gexec
SELECT format('ALTER SCHEMA wazync OWNER TO %I', :'wazync_user')
\gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA wazync TO %I', :'wazync_user')
\gexec
-- O schema é provisionado pelo bootstrap; o runtime/migrator só cria objetos dentro dele.
SELECT format('REVOKE CREATE ON DATABASE %I FROM %I', current_database(), :'wazync_user')
\gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'wazync_user')
\gexec
SELECT format('REVOKE ALL ON SCHEMA public FROM %I', :'wazync_user')
\gexec
SELECT format('ALTER ROLE %I IN DATABASE %I SET search_path = wazync', :'wazync_user', current_database())
\gexec
SQL
