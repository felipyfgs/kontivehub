# syntax=docker/dockerfile:1

FROM nginx:1.27-alpine AS development

COPY infra/docker/nginx/conf/default.conf /etc/nginx/conf.d/default.conf

FROM nginx:1.27-alpine AS production

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="kontivehub-nginx"

RUN mkdir -p /var/empty \
  && rm -rf /usr/share/nginx/html/*

COPY infra/docker/nginx/conf/production.conf /etc/nginx/kontivehub.conf.template
COPY --chmod=755 infra/docker/nginx/render-production-config.sh /docker-entrypoint.d/05-render-kontivehub-config.sh

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD wget -qO- http://127.0.0.1:8080/nginx-health >/dev/null || exit 1
