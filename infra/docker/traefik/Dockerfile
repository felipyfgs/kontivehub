# syntax=docker/dockerfile:1

FROM alpine:3.22 AS init

COPY --chmod=755 init.sh /usr/local/bin/acme-init

ENTRYPOINT ["acme-init"]

FROM traefik:v3.6 AS prod
