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

exec "$@"
