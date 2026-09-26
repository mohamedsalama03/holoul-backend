#!/bin/sh
set -eu
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ "${2:-}" != docker/app/initialize.php ]; then
    # An old compiled configuration must never hide the supplied environment.
    if [ "${HOLOUL_DEPLOYMENT_PROFILE:-production}" = production ] && [ "${APP_ENV:-production}" != production ]; then
        printf '%s\n' '{"event":"startup.configuration_rejected","violations":["production_environment_required"]}' >&2
        exit 1
    fi
    if [ "${APP_ENV:-production}" = production ]; then
        umask 077
        b8_application_root="$(pwd -P)"
        b8_cache_directory="${b8_application_root}/bootstrap/cache"
        # Resolved secrets belong only to this process container's private tmpfs.
        if [ -L bootstrap/cache ] || [ "$(cd bootstrap/cache && pwd -P)" != "$b8_cache_directory" ] \
            || [ "$(stat -f -c '%T' "$b8_cache_directory")" != tmpfs ]; then
            printf '%s\n' '{"event":"startup.configuration_rejected","violations":["private_runtime_cache_required"]}' >&2
            exit 1
        fi
        chmod 700 "$b8_cache_directory"
        export APP_CONFIG_CACHE="${b8_cache_directory}/config.php"
        export APP_ROUTES_CACHE="${b8_cache_directory}/routes-v7.php"
        export APP_PACKAGES_CACHE="${b8_cache_directory}/packages.php"
        export APP_SERVICES_CACHE="${b8_cache_directory}/services.php"
        export APP_EVENTS_CACHE="${b8_cache_directory}/events.php"
        rm -f -- "$APP_CONFIG_CACHE" "$APP_ROUTES_CACHE"
    fi
    if [ "${APP_ENV:-production}" != testing ] || [ "${HOLOUL_DEPLOYMENT_PROFILE:-production}" != local-verification ]; then
        php docker/app/validate-runtime.php
    fi
    if [ "${APP_ENV:-production}" = production ]; then
        if ! php artisan config:cache --no-interaction > /dev/null 2>&1 \
            || ! php artisan route:cache --no-interaction > /dev/null 2>&1; then
            printf '%s\n' '{"event":"startup.configuration_rejected","violations":["runtime_cache_compilation_failed"]}' >&2
            exit 1
        fi
        chmod 600 "$APP_CONFIG_CACHE" "$APP_ROUTES_CACHE"
        php docker/app/validate-runtime.php
    fi
fi
exec "$@"
