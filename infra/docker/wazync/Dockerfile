# syntax=docker/dockerfile:1

FROM golang:1.25-alpine AS builder

RUN apk add --no-cache ca-certificates git

WORKDIR /src/apps/wazync

COPY apps/wazync/go.mod apps/wazync/go.sum ./
RUN go mod download

COPY apps/wazync/ ./

ARG TARGETOS=linux
ARG TARGETARCH
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} \
    go build -trimpath -ldflags="-s -w" -o /out/wazync ./cmd/wazync

FROM alpine:3.22 AS runtime

RUN apk add --no-cache ca-certificates tzdata \
    && addgroup -S -g 10001 wazync \
    && adduser -S -D -H -u 10001 -G wazync wazync \
    && install -d -o wazync -g wazync -m 0700 /var/lib/wazync/spool

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://github.com/local/fiscal-hub" \
      org.opencontainers.image.title="fiscal-hub-wazync"

COPY --from=builder --chown=wazync:wazync /out/wazync /usr/local/bin/wazync

ENV WAZYNC_HTTP_ADDRESS=:8080 \
    WAZYNC_SPOOL_DIR=/var/lib/wazync/spool

USER wazync:wazync
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 \
    CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/wazync"]
