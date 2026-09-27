#!/bin/sh
set -e

# The docker-compose bind mount (.:/var/www/html) replaces whatever
# ownership the image set at build time with the host filesystem's
# ownership, every time the container starts -- so storage/ and
# bootstrap/cache/ come back owned by whoever owns them on the host, not
# www-data, and PHP-FPM (which runs as www-data) can't write sessions,
# views or logs. Fixing this once at startup, rather than only in the
# Dockerfile, is what makes it survive the bind mount.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Railway (and most PaaS platforms) assign the public port at runtime via
# $PORT rather than letting the container pick one -- nginx's hardcoded
# "listen 80" only works for local Compose, where the host port mapping
# does the translation instead. Render the real config from the template
# on every start, defaulting to 80 so docker-compose (which never sets
# PORT) is unaffected.
if [ -f /etc/nginx/sites-enabled/web.conf.template ]; then
    PORT="${PORT:-80}" envsubst '${PORT}' \
        < /etc/nginx/sites-enabled/web.conf.template \
        > /etc/nginx/sites-enabled/default
fi

exec "$@"
