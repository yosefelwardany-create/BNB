#!/bin/sh
#
# Queue worker.
#
# Queues are named so a burst of channel synchronisation cannot delay a guest's
# booking confirmation. They are listed here in priority order: the worker
# drains everything on an earlier queue before looking at a later one.

set -e

exec php artisan queue:work redis \
    --queue=messaging,default,automation,webhooks,channels,ai,reports,imports \
    --tries=3 \
    --backoff=10,30,120 \
    --max-jobs=1000 \
    --max-time=3600 \
    --timeout=300 \
    --sleep=1 \
    --rest=0.2
