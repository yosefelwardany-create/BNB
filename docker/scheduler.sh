#!/bin/sh
#
# Scheduler tick.
#
# Render invokes this once a minute; Laravel decides what is actually due.
# Anything that fires "at a time of day" for a property resolves that time in
# the property's own timezone inside the job, not against this clock.

set -e

exec php artisan schedule:run --verbose --no-interaction
