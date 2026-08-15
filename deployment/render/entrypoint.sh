#!/bin/bash
set -Eeuo pipefail

prepare_runtime() {
    mkdir -p \
        storage/app/private/purchase-order-payment-receipts \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

    chown -R www-data:www-data storage bootstrap/cache
}

validate_production_environment() {
    if [ "${APP_ENV:-}" != "production" ]; then
        return
    fi

    if [ "${APP_DEBUG:-false}" != "false" ]; then
        echo "APP_DEBUG must be false in production." >&2
        exit 1
    fi

    case "${APP_URL:-}" in
        https://*) ;;
        *)
            echo "APP_URL must use HTTPS in production." >&2
            exit 1
            ;;
    esac

    if [ -z "${DB_URL:-}" ]; then
        echo "DB_URL must be set in production." >&2
        exit 1
    fi

    if [ -z "${REDIS_URL:-}" ]; then
        echo "REDIS_URL must be set in production." >&2
        exit 1
    fi

    php -r '
        $key = getenv("APP_KEY") ?: "";
        $encoded = str_starts_with($key, "base64:") ? substr($key, 7) : "";
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            fwrite(STDERR, "APP_KEY must be a generated 32-byte base64 Laravel key.\n");
            exit(1);
        }
    '
}

start_web() {
    export PORT="${PORT:-10000}"
    envsubst '${PORT}' \
        < /etc/nginx/templates/default.conf.template \
        > /etc/nginx/conf.d/default.conf

    php-fpm -F &
    local php_fpm_pid=$!

    nginx -g 'daemon off;' &
    local nginx_pid=$!

    terminate() {
        kill -TERM "$nginx_pid" "$php_fpm_pid" 2>/dev/null || true
        wait "$nginx_pid" "$php_fpm_pid" 2>/dev/null || true
    }

    trap terminate TERM INT

    set +e
    wait -n "$nginx_pid" "$php_fpm_pid"
    local status=$?
    set -e

    terminate
    exit "$status"
}

prepare_runtime
validate_production_environment

if [ "${1:-web}" = "web" ]; then
    start_web
fi

exec gosu www-data "$@"
