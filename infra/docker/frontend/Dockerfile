# syntax=docker/dockerfile:1

FROM node:24-bookworm AS development

WORKDIR /app

RUN corepack enable

COPY --chmod=755 infra/docker/frontend/entrypoint.sh /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["dev"]

FROM node:24-bookworm AS web-builder

WORKDIR /workspace/apps/web

RUN corepack enable

COPY apps/web/package.json apps/web/pnpm-lock.yaml apps/web/pnpm-workspace.yaml ./
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    corepack pnpm install --frozen-lockfile

COPY apps/web/ ./

ARG NUXT_PUBLIC_COMMUNICATION_ENABLED=false
ARG NUXT_PUBLIC_REVERB_APP_KEY=communication-disabled

ENV NODE_ENV=production \
    NUXT_DEVTOOLS=false \
    CHOKIDAR_USEPOLLING=false \
    NUXT_PUBLIC_COMMUNICATION_ENABLED=${NUXT_PUBLIC_COMMUNICATION_ENABLED} \
    NUXT_PUBLIC_REVERB_APP_KEY=${NUXT_PUBLIC_REVERB_APP_KEY} \
    NUXT_PUBLIC_REVERB_HOST= \
    NUXT_PUBLIC_REVERB_PORT=0 \
    NUXT_PUBLIC_REVERB_SCHEME=https

RUN corepack pnpm run generate

FROM nginx:1.27-alpine AS production

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://github.com/inovaicontabil/kontivehub" \
      org.opencontainers.image.title="kontivehub-web"

COPY infra/docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY infra/docker/frontend/security-headers.conf /etc/nginx/security-headers.conf
COPY --from=web-builder /workspace/apps/web/.output/public /usr/share/nginx/html

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
    CMD wget -qO- http://127.0.0.1/health >/dev/null || exit 1
