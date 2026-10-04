#!/bin/sh
# Entrypoint shared by the app, queue, scheduler and vite services.
set -e

if [ "${APP_ENV:-local}" = "production" ]; then
    # Cache config, routes and views. Skipped in development so code
    # changes are picked up immediately.
    php artisan optimize

    # Only one service (app) sets RUN_MIGRATIONS, to avoid concurrent migrations.
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        php artisan migrate --force
    fi
fi

exec "$@"
