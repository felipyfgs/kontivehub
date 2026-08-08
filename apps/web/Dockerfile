# syntax=docker/dockerfile:1

FROM node:24-bookworm AS development

WORKDIR /app

RUN corepack enable

COPY --chmod=755 apps/web/docker/entrypoint.sh /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["dev"]

FROM node:24-bookworm AS web-dependencies

WORKDIR /workspace/apps/web

RUN corepack enable

COPY apps/web/package.json apps/web/pnpm-lock.yaml apps/web/pnpm-workspace.yaml ./
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    corepack pnpm install --frozen-lockfile

FROM web-dependencies AS test

COPY apps/web/ ./
COPY apps/api/resources/contracts /workspace/api-contracts
COPY apps/api/app/Services/FiscalMonitoring/Surfaces /workspace/api-monitoring-surfaces
COPY docker/nginx /workspace/nginx

ENV CI=true \
    NODE_ENV=test \
    NUXT_DEVTOOLS=false \
    NUXT_UI_FONTS=false \
    CHOKIDAR_USEPOLLING=false \
    PNPM_CONFIG_VERIFY_DEPS_BEFORE_RUN=false

RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    corepack pnpm install --offline --frozen-lockfile

CMD ["corepack", "pnpm", "run", "test:gate"]

FROM web-dependencies AS web-builder

COPY apps/web/ ./

RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    corepack pnpm install --offline --frozen-lockfile

ARG NUXT_PUBLIC_COMMUNICATION_ENABLED=false
ARG NUXT_PUBLIC_REVERB_APP_KEY=communication-disabled
ARG NUXT_PUBLIC_REVERB_HOST=
ARG NUXT_PUBLIC_REVERB_PORT=0
ARG NUXT_PUBLIC_REVERB_SCHEME=https

ENV NODE_ENV=production \
    NUXT_DEVTOOLS=false \
    CHOKIDAR_USEPOLLING=false \
    NUXT_PUBLIC_COMMUNICATION_ENABLED=${NUXT_PUBLIC_COMMUNICATION_ENABLED} \
    NUXT_PUBLIC_REVERB_APP_KEY=${NUXT_PUBLIC_REVERB_APP_KEY} \
    NUXT_PUBLIC_REVERB_HOST=${NUXT_PUBLIC_REVERB_HOST} \
    NUXT_PUBLIC_REVERB_PORT=${NUXT_PUBLIC_REVERB_PORT} \
    NUXT_PUBLIC_REVERB_SCHEME=${NUXT_PUBLIC_REVERB_SCHEME}

RUN corepack pnpm run generate

FROM nginx:1.27-alpine AS production

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="kontivehub-web"

COPY apps/web/docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY apps/web/docker/security-headers.conf /etc/nginx/security-headers.conf
COPY --from=web-builder /workspace/apps/web/.output/public /usr/share/nginx/html

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
    CMD wget -qO- http://127.0.0.1/health >/dev/null || exit 1
