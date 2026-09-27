#!/bin/sh
set -e

# Runs on every nginx start attempt, not just the container's first boot --
# supervisord restarts nginx directly (bypassing entrypoint.sh entirely) on
# crash, and if the very first config render ever failed for any reason
# (e.g. $PORT not yet visible to that exact process at container-init
# time), every subsequent restart would just keep retrying the same bad
# config forever. Regenerating here, in the actual nginx start path, means
# a later-arriving $PORT still gets picked up on the next restart.
export PORT="${PORT:-80}"
envsubst '${PORT}' \
    < /etc/nginx/sites-enabled/web.conf.template \
    > /etc/nginx/sites-enabled/default

exec nginx -g "daemon off;"
