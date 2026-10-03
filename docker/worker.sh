#!/bin/sh
#
# Queue worker and independent inbound import timer.
#
# Queues are named so a burst of channel synchronisation cannot delay a guest's
# booking confirmation. They are listed in worker.conf in priority order: the worker
# drains everything on an earlier queue before looking at a later one.

set -e

exec supervisord -n -c /etc/supervisor/habitat-worker.conf
