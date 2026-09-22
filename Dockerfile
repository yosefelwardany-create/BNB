#
# Production image for the Habitat PMS.
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
# Its own stage, so Node and node_modules never reach the runtime image.
FROM node:22-alpine AS frontend

WORKDIR /build

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------
# 2. Runtime, including the PHP dependency install
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS runtime

# Extensions are installed before Composer runs, because Composer verifies the
# platform's extensions against what the dependencies require. Building
# dependencies in a separate `composer` image would fail here: that image has
# no gd or zip, and phpoffice/phpspreadsheet and Laravel's image handling
# require both. Installing in the image that will actually run the code also
# guarantees the build platform and the runtime platform cannot drift apart.
#
#   intl               locale-aware dates and currency
#   pcntl              graceful shutdown signals for queue workers
#   pdo_pgsql, pgsql   PostgreSQL
#   redis              cache, queues and the locks that prevent double bookings
#   gd, exif, zip      property photography, spreadsheet export
#   bcmath             arbitrary-precision arithmetic
#   opcache            bytecode cache
RUN install-php-extensions \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        redis \
        gd \
        exif \
        zip \
        bcmath \
        opcache

# psql is kept for operational access: inspecting a production database from a
# shell is worth the few megabytes. libcap2-bin supplies setcap/getcap, needed
# by the step below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

# The FrankenPHP image grants its binary the cap_net_bind_service file
# capability so it can bind port 80 as an unprivileged user. That capability is
# useless here — the service listens on $PORT, which is 10000 — and it is
# actively harmful: a container platform that sets the no_new_privs flag (Render
# does) refuses to execve any file carrying capabilities, so the exec fails with
# EPERM and the container exits 126 before the server ever starts.
#
# Stripping the capability is therefore both the fix and the correct hardening:
# nothing in this image needs to bind a privileged port.
RUN setcap -r /usr/local/bin/frankenphp 2>/dev/null || true \
    && echo "frankenphp capabilities after strip: $(getcap /usr/local/bin/frankenphp || echo none)"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Dependencies are installed before the application is copied, so a change to
# application code does not invalidate the dependency layer.
COPY composer.json composer.lock ./

# Scripts are skipped and the autoloader deferred because the application code
# they need is not present yet.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .
COPY --from=frontend /build/dist ./public/app

RUN composer dump-autoload --optimize --no-dev --no-interaction

COPY docker/php.ini /usr/local/etc/php/conf.d/habitat.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/worker.sh /usr/local/bin/worker
COPY docker/scheduler.sh /usr/local/bin/scheduler

RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/worker /usr/local/bin/scheduler \
    && mkdir -p storage/framework/cache/data \
               storage/framework/sessions \
               storage/framework/views \
               storage/logs \
               bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Render (and most platforms) inject the port to listen on.
ENV PORT=10000
ENV SERVER_NAME=":10000"
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["/usr/local/bin/frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
