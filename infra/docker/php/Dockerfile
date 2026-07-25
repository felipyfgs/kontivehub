# syntax=docker/dockerfile:1

FROM php:8.4-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    gosu \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    postgresql-client \
    poppler-utils \
    $PHPIZE_DEPS \
  && docker-php-ext-configure intl \
  && docker-php-ext-install -j$(nproc) \
    curl \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    bcmath \
    opcache \
    pcntl \
    soap \
  && pecl install redis \
  && docker-php-ext-enable redis \
  && apt-get purge -y --auto-remove $PHPIZE_DEPS \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY conf.d/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY conf.d/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

RUN usermod -u 1000 www-data && groupmod -g 1000 www-data \
  && mkdir -p /var/vault \
  && chown -R www-data:www-data /var/vault

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM base AS rpa-base

ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright \
    FGTS_DIGITAL_PYTHON=/opt/fgts-rpa/bin/python

RUN apt-get update \
  && apt-get install -y --no-install-recommends python3 python3-venv ca-certificates \
  && python3 -m venv /opt/fgts-rpa \
  && /opt/fgts-rpa/bin/pip install --no-cache-dir playwright==1.61.0 \
  && /opt/fgts-rpa/bin/playwright install --with-deps chromium \
  && chmod -R a+rX /opt/fgts-rpa /opt/ms-playwright \
  && rm -rf /var/lib/apt/lists/*

FROM base AS prod

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://github.com/local/fiscal-hub" \
      org.opencontainers.image.title="fiscal-hub-php"

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1 \
    RELEASE_SHA=${RELEASE_SHA}

COPY --from=app --chown=www-data:www-data . /var/www/html

RUN composer install \
      --no-dev \
      --no-interaction \
      --no-progress \
      --prefer-dist \
      --no-scripts \
      --classmap-authoritative \
  && chown -R www-data:www-data \
      /var/www/html/bootstrap/cache \
      /var/www/html/storage

FROM base AS dev

FROM rpa-base AS rpa-dev

FROM rpa-base AS rpa-prod

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://github.com/local/fiscal-hub" \
      org.opencontainers.image.title="fiscal-hub-php-rpa"

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1 \
    RELEASE_SHA=${RELEASE_SHA}

COPY --from=app --chown=www-data:www-data . /var/www/html

RUN composer install \
      --no-dev \
      --no-interaction \
      --no-progress \
      --prefer-dist \
      --no-scripts \
      --classmap-authoritative \
  && chown -R www-data:www-data \
      /var/www/html/bootstrap/cache \
      /var/www/html/storage
