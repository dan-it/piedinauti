# syntax=docker/dockerfile:1
#
# Multi-stage image for the Laravel application:
#   base    -> PHP-FPM with the extensions Laravel + PostgreSQL need
#   dev     -> base + Node.js (used for Vite and for composer/artisan/npm in development)
#   builder -> dev + application code, vendor/ and compiled frontend assets
#   prod    -> base + the built application (no Node.js, no dev tools)
#   web     -> Caddy serving the built public/ directory (production only)

ARG PHP_VERSION=8.3

# ---------------------------------------------------------------- base
FROM php:${PHP_VERSION}-fpm-bookworm AS base

ARG HOST_UID=1000
ARG HOST_GID=1000

# System libraries and PHP extensions: PostgreSQL driver, intl, zip, bcmath,
# pcntl (queue workers) and opcache.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Give www-data the host user's uid/gid so files created in bind-mounted
# directories stay owned by the developer on the host.
RUN groupmod -o -g "${HOST_GID}" www-data \
    && usermod -o -u "${HOST_UID}" www-data

# Composer and npm need writable cache directories for a non-root user.
ENV COMPOSER_HOME=/tmp/composer \
    npm_config_cache=/tmp/npm

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ----------------------------------------------------------------- dev
FROM base AS dev

# Node.js is required for the Vite dev server and for building assets.
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

USER www-data

# ------------------------------------------------------------- builder
FROM dev AS builder

USER root
COPY src/ /var/www/html/

# A throwaway APP_KEY is enough for the build; the real key is provided at runtime.
# Wayfinder/Vite run artisan during the build, so PHP dependencies come first.
RUN export APP_ENV=production \
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" \
    && composer install --no-dev --optimize-autoloader --no-interaction \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

# ---------------------------------------------------------------- prod
FROM base AS prod

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

USER www-data

# ----------------------------------------------------------------- web
FROM caddy:2-alpine AS web

COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
# Only static files are needed here; PHP requests are forwarded to the app service.
COPY --from=builder /var/www/html/public /var/www/html/public
