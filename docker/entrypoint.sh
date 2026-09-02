#!/bin/sh
set -e

# Cloud Run Jobs (and plain `docker run <image> <command>`) override the
# container's command to do one-off work — most notably running database
# migrations — against this same image instead of starting the web server.
# See docs/deployment-gcp.md for how the migration Job is set up.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

export PORT="${PORT:-8080}"
envsubst '${PORT}' < /etc/nginx/templates/nginx.conf.template > /etc/nginx/nginx.conf

# Config/route/view caching is done here at container start, not at image
# build time, because APP_KEY/DB_*/etc. only exist as env vars once Cloud
# Run injects them (from plain env vars and Secret Manager) at runtime —
# caching at build time would bake in empty values.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec supervisord -n -c /etc/supervisord.conf
