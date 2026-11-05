# syntax=docker/dockerfile:1
#
# Two targets share one PHP setup:
#   dev   the development image: Composer, Xdebug (off until XDEBUG_MODE says otherwise), your uid/gid.
#         The sources are mounted, not copied (docker-compose.dev.yml).
#   prod  the published image: dependencies and assets built in, OPcache, FrankenPHP, no root.
# FrankenPHP serves the application in classic mode (one PHP request per request, no extra package), so
# the same PHP runs the web server, the queue and the scheduler, and Caddy replaces nginx and php-fpm.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm

FROM ${PHP_IMAGE} AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql intl zip gd bcmath exif pcntl redis opcache
COPY docker/app/php/common.ini $PHP_INI_DIR/conf.d/zz-common.ini
ENV COMPOSER_MEMORY_LIMIT=-1
# The base image probes Caddy's admin API, which only the web server has (and prod turns off): compose defines the checks.
HEALTHCHECK NONE
WORKDIR /app

FROM base AS builder
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM builder AS dev
ARG UID=1000
ARG GID=1000
RUN install-php-extensions xdebug
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/app/php/dev.ini $PHP_INI_DIR/conf.d/zz-dev.ini
# -o: a Mac user's gid (20, staff) already exists in Debian as another group.
RUN groupadd -o -g "$GID" app \
    && useradd -o -m -u "$UID" -g "$GID" -s /bin/bash app
COPY docker/app/dev-entrypoint.sh /usr/local/bin/entrypoint
COPY docker/app/watch-restart.sh /usr/local/bin/watch-restart
USER app
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# The frontend does not depend on the CPU: build it once, natively, for every platform.
FROM --platform=$BUILDPLATFORM node:22 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources resources
RUN npm run build

FROM builder AS build
COPY composer.json composer.lock ./
COPY contracts contracts
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
COPY --from=assets /app/public/build public/build
# The composer scripts boot the application: keep them away from Redis and the database.
RUN CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
        composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/app/public storage/app/private storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && ln -sfn /app/storage/app/public public/storage

FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/app/php/prod.ini $PHP_INI_DIR/conf.d/zz-prod.ini
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/prod-entrypoint.sh /usr/local/bin/entrypoint
RUN useradd -u 1000 -m -s /bin/bash app \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data/caddy /config/caddy
COPY --from=build --chown=app:app /app /app
ENV APP_ENV=production
USER app
EXPOSE 8080
ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
