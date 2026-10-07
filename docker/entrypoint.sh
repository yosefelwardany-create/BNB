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

    # A property with no listing cannot be booked, put on a calendar or priced,
    # because all three take a listing. Properties created before that became
    # automatic are in that state and there is no way to run a command against a
    # deployed instance, so the repair runs here. Additive and idempotent: it
    # creates what is missing, edits nothing, and once none are left it says so.
    php artisan properties:ensure-listings

    # Every client account holds an account-holder owner record, a management
    # agreement and an ownership row per property. Additive and idempotent, like
    # the listing repair above: it creates what is missing and overwrites
    # nothing. Membership conversion is a separate, explicit step
    # (clients:provision --reconcile-memberships) that is never run on boot.
    php artisan clients:provision

    # A sample client account (Juan Lopez, five properties in Bogotá) for the
    # live platform: no login, no channel connection, nothing sent to anyone.
    # Runs once; with the account already there it changes nothing. The sync
    # queue keeps its follow-up work inside its own transaction.
    if [ "$SEED_JUAN_LOPEZ_SAMPLE" = "true" ]; then
        echo "[habitat] creating the Juan Lopez sample account..."
        QUEUE_CONNECTION=sync php artisan db:seed --class=JuanLopezDemoSeeder --force \
            || echo "[habitat] the Juan Lopez sample account could not be created; nothing was kept." >&2
    fi

    # The Bogota Colombia sample account (ten properties), built the same way,
    # and then the Demo Hospitality Group account it replaces: that one's
    # logins share a published password. The demo account is kept while
    # SEED_DEMO_DATA is true, since the demo seeder below would rebuild it.
    if [ "$SEED_BOGOTA_SAMPLE" = "true" ]; then
        echo "[habitat] creating the Bogota Colombia sample account..."
        QUEUE_CONNECTION=sync php artisan db:seed --class=BogotaColombiaSampleSeeder --force \
            || echo "[habitat] the Bogota Colombia sample account could not be created; nothing was kept." >&2

        if [ "$SEED_DEMO_DATA" = "true" ]; then
            echo "[habitat] Demo Hospitality Group kept: SEED_DEMO_DATA is true." >&2
        else
            php artisan db:seed --class=RetireDemoHospitalitySeeder --force \
                || echo "[habitat] Demo Hospitality Group could not be removed; nothing was changed." >&2
        fi
    fi

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

# A failed exec leaves no useful trace: the shell prints one line and the
# container exits 126, which looks identical whether the binary is missing, not
# executable, or refused by the platform. The most likely cause of the last is a
# file capability on a host that sets no_new_privs, so that is reported by name.
case "$1" in
    */*) target="$1" ;;
    *)   target=$(command -v "$1" 2>/dev/null || true) ;;
esac

if [ -z "$target" ] || [ ! -e "$target" ]; then
    echo "[habitat] cannot start: '$1' was not found." >&2
    exit 127
fi

if [ ! -x "$target" ]; then
    echo "[habitat] cannot start: '$target' is not executable." >&2
    exit 126
fi

if command -v getcap >/dev/null 2>&1; then
    caps=$(getcap "$target" 2>/dev/null || true)
    if [ -n "$caps" ]; then
        echo "[habitat] warning: $caps" >&2
        echo "[habitat] a binary carrying file capabilities cannot be executed on a host that sets no_new_privs; strip them at build time with 'setcap -r'." >&2
    fi
fi

exec "$@"
