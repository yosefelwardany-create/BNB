#
# Production image for the Habitat PMS API.
#
# FrankenPHP is used rather than nginx + php-fpm + supervisor because it is a
# single process that serves HTTP and runs PHP, which is exactly what a
# container platform wants: one process to supervise, one port to expose, and
# no init system inside the container.
#
# The same image runs the web service, the queue workers and the scheduler —
# they differ only in the command they are started with, so what is tested is
# what runs in every role.

# ---------------------------------------------------------------------------
# 1. Front-end build
# ---------------------------------------------------------------------------
# Built in its own stage so Node and the node_modules tree never reach the
# runtime image.
FROM node:22-alpine AS frontend

WORKDIR /build

COPY frontend/package*.json ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# 2. PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Dev dependencies are excluded, and scripts are skipped because the
# application code needed by package discovery is not present yet.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---------------------------------------------------------------------------
# 3. Runtime
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS runtime

# intl  — locale-aware formatting for dates and currency
# pcntl — required by queue workers for graceful shutdown signals
# pdo_pgsql / pgsql — PostgreSQL
# redis — cache, queues and locks
# gd    — image handling for property photography
# zip   — spreadsheet and document export
RUN install-php-extensions \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        redis \
        gd \
        zip \
        opcache \
        bcmath \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=frontend /build/dist /app/public/app

COPY docker/php.ini /usr/local/etc/php/conf.d/habitat.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/worker.sh /usr/local/bin/worker
COPY docker/scheduler.sh /usr/local/bin/scheduler

RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/worker /usr/local/bin/scheduler \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Render (and most platforms) inject the port to listen on.
ENV PORT=10000
ENV SERVER_NAME=":10000"
EXPOSE 10000

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
