#!/bin/sh
set -eu
umask 027
for secret_name in APP_KEY PRODUCER_LOOKUP_KEY DB_PASSWORD REDIS_PASSWORD; do
    secret_path="$(printenv "${secret_name}_FILE" || true)"
    if [ -n "$secret_path" ]; then
        case "$secret_path" in /run/secrets/*) ;; *) echo "Invalid secret mount." >&2; exit 1;; esac
        [ -r "$secret_path" ] || { echo "Required secret is unreadable." >&2; exit 1; }
        secret_value="$(cat "$secret_path")"
        [ -n "$secret_value" ] || { echo "Required secret is empty." >&2; exit 1; }
        export "$secret_name=$secret_value"
        unset secret_value
    fi
done
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views
if [ "$1" = "php-fpm" ] || [ "$1" = "scheduler" ]; then
    php artisan agroconecta:security-check --production --no-interaction
fi
if [ "$1" = "scheduler" ]; then
    trap 'exit 0' TERM INT
    while :; do
        php artisan schedule:run --no-interaction
        sleep 60 &
        wait $! || exit 0
    done
fi
exec "$@"

