#!/bin/sh
#
# Container entrypoint.
#
# Prepares the application, then hands over to whatever command the container
# was started with (the web server, a queue worker, or the scheduler).

set -e

echo "[habitat] starting as: $*"

# Render injects PORT; Caddy binds to SERVER_NAME.
if [ -n "$PORT" ]; then
    export SERVER_NAME=":$PORT"
fi

# Fail fast and loudly on a missing key rather than booting an application
# that cannot decrypt the data it is about to read.
if [ -z "$APP_KEY" ]; then
    echo "[habitat] APP_KEY is not set." >&2
    echo "[habitat] Generate one with 'php artisan key:generate --show' and set it in the service's environment." >&2
    exit 1
fi

# Wait for the database. A fresh Render deploy can start the service before
# the managed database finishes provisioning.
if [ -n "$DB_URL" ] || [ -n "$DB_HOST" ]; then
    echo "[habitat] waiting for the database..."
    attempt=0
    until php artisan db:show --quiet >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "[habitat] the database did not become reachable in time." >&2
            exit 1
        fi
        sleep 2
    done
    echo "[habitat] database is reachable."
fi

# Only one role migrates, so a deploy cannot run migrations concurrently from
# the web service, the worker and the cron job.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "[habitat] running migrations..."
    php artisan migrate --force --isolated

    echo "[habitat] synchronising reference data..."
    php artisan permissions:sync
    php artisan amenities:sync

    if [ "$SEED_DEMO_DATA" = "true" ]; then
        echo "[habitat] seeding demonstration data..."
        php artisan db:seed --class=DemoSeeder --force
    fi
fi

# Caches are rebuilt on every boot because the filesystem is immutable and the
# environment can differ between deploys.
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Public storage symlink for locally stored uploads.
php artisan storage:link --force >/dev/null 2>&1 || true

echo "[habitat] ready."

exec "$@"
