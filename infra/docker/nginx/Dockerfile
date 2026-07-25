# syntax=docker/dockerfile:1

FROM nginx:1.27-alpine AS runtime

FROM node:24-bookworm AS spa-builder

WORKDIR /app

COPY --from=ui package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN corepack pnpm install --frozen-lockfile

COPY --from=ui . .

ENV NUXT_DEVTOOLS=false \
    NUXT_SANCTUM_PROXY=false

ARG NUXT_PUBLIC_API_BASE=https://api.kontivehub.com.br
ARG NUXT_PUBLIC_COMMUNICATION_ENABLED=false
ARG NUXT_PUBLIC_REVERB_APP_KEY=communication-disabled
ARG NUXT_PUBLIC_REVERB_HOST=api.kontivehub.com.br
ARG NUXT_PUBLIC_REVERB_PORT=443
ARG NUXT_PUBLIC_REVERB_SCHEME=https

ENV NUXT_PUBLIC_API_BASE=${NUXT_PUBLIC_API_BASE} \
    NUXT_PUBLIC_COMMUNICATION_ENABLED=${NUXT_PUBLIC_COMMUNICATION_ENABLED} \
    NUXT_PUBLIC_REVERB_APP_KEY=${NUXT_PUBLIC_REVERB_APP_KEY} \
    NUXT_PUBLIC_REVERB_HOST=${NUXT_PUBLIC_REVERB_HOST} \
    NUXT_PUBLIC_REVERB_PORT=${NUXT_PUBLIC_REVERB_PORT} \
    NUXT_PUBLIC_REVERB_SCHEME=${NUXT_PUBLIC_REVERB_SCHEME}

RUN corepack pnpm run generate

FROM runtime AS prod

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://github.com/local/fiscal-hub" \
      org.opencontainers.image.title="fiscal-hub-web"

ENV RELEASE_SHA=${RELEASE_SHA}

COPY conf/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=spa-builder /app/.output/public /usr/share/nginx/html

FROM runtime AS dev

COPY conf/dev.conf /etc/nginx/conf.d/default.conf
