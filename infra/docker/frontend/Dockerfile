# syntax=docker/dockerfile:1

FROM node:24-bookworm

WORKDIR /app

COPY --chmod=755 entrypoint.sh /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["dev"]
