# syntax=docker/dockerfile:1

FROM php:8.4-fpm-bookworm AS php-extensions

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    $PHPIZE_DEPS \
  && docker-php-ext-configure intl \
  && docker-php-ext-install -j"$(nproc)" \
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
  && apt-mark manual libpq5 libzip4 libicu72 libpng16-16 libxml2 libcurl4 libssl3 \
  && apt-get purge -y --auto-remove \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    $PHPIZE_DEPS \
  && rm -rf /tmp/pear /var/lib/apt/lists/*

COPY apps/api/docker/conf.d/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY apps/api/docker/conf.d/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY apps/api/docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

FROM php-extensions AS development

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    gosu \
    poppler-utils \
    postgresql-client \
    unzip \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --chmod=755 apps/api/docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN mkdir -p /var/vault \
  && chown -R www-data:www-data /var/vault

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# Compatibilidade com os nomes usados pelo Compose local.
FROM development AS runtime

FROM development AS test

ENV APP_ENV=testing \
    APP_DEBUG=false \
    COMPOSER_HOME=/tmp/composer

COPY apps/api/composer.json apps/api/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --prefer-dist

COPY --chown=www-data:www-data apps/api/ ./

RUN install -o www-data -g www-data -m 0600 /dev/null .env \
  && composer dump-autoload \
      --no-interaction \
      --no-scripts \
      --optimize

CMD ["sh", "-ec", "composer validate --strict --no-check-publish && vendor/bin/pint --test && php -d memory_limit=512M artisan test"]

FROM development AS vendor-builder

WORKDIR /build/app

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --classmap-authoritative

COPY apps/api/ ./

RUN composer dump-autoload \
      --no-dev \
      --no-interaction \
      --no-scripts \
      --classmap-authoritative \
  && rm -rf \
      .dockerignore \
      .editorconfig \
      .env.example \
      .gitattributes \
      .local \
      auth.json \
      coverage \
      phpunit.canon.xml \
      phpunit.xml \
      tests \
      tools \
  && find . -type d -name .git -prune -exec rm -rf '{}' + \
  && find . -type f \( -name '*.log' -o -name '.DS_Store' \) -delete \
  && rm -rf storage/framework/cache/data/* storage/framework/sessions/* \
      storage/framework/testing/* storage/framework/views/* storage/logs/* \
      bootstrap/cache/*.php

FROM php-extensions AS production

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    gosu \
    poppler-utils \
    postgresql-client \
  && rm -rf /var/lib/apt/lists/*

ARG RELEASE_SHA=unknown
ARG BUILD_DATE=

LABEL org.opencontainers.image.revision="${RELEASE_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="kontivehub-api"

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --from=vendor-builder --chown=www-data:www-data /build/app /var/www/html
COPY --chmod=644 apps/api/docker/conf.d/opcache.production.ini /usr/local/etc/php/conf.d/zz-opcache-production.ini
COPY --chmod=755 apps/api/docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN mkdir -p \
      /var/vault \
      /var/www/html/storage/app/private \
      /var/www/html/storage/framework/cache \
      /var/www/html/storage/framework/sessions \
      /var/www/html/storage/framework/views \
      /var/www/html/storage/logs \
      /var/www/html/bootstrap/cache \
  && chown -R www-data:www-data \
      /var/vault \
      /var/www/html/storage \
      /var/www/html/bootstrap/cache

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM development AS rpa-development

ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright \
    FGTS_DIGITAL_PYTHON=/opt/fgts-rpa/bin/python

RUN apt-get update \
  && apt-get install -y --no-install-recommends python3 python3-venv ca-certificates \
  && python3 -m venv /opt/fgts-rpa \
  && /opt/fgts-rpa/bin/pip install --no-cache-dir playwright==1.61.0 \
  && /opt/fgts-rpa/bin/playwright install --with-deps chromium \
  && chmod -R a+rX /opt/fgts-rpa /opt/ms-playwright \
  && rm -rf /var/lib/apt/lists/*

FROM rpa-development AS rpa-runtime

FROM production AS rpa-production

ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright \
    FGTS_DIGITAL_PYTHON=/opt/fgts-rpa/bin/python

RUN apt-get update \
  && apt-get install -y --no-install-recommends python3 python3-venv ca-certificates \
  && python3 -m venv /opt/fgts-rpa \
  && /opt/fgts-rpa/bin/pip install --no-cache-dir playwright==1.61.0 \
  && /opt/fgts-rpa/bin/playwright install --with-deps chromium \
  && chmod -R a+rX /opt/fgts-rpa /opt/ms-playwright \
  && rm -rf /var/lib/apt/lists/*

LABEL org.opencontainers.image.title="kontivehub-api-rpa"
