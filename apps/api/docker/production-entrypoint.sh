#!/bin/sh
set -eu

load_secret() {
    variable_name="$1"
    file_variable_name="${variable_name}_FILE"
    eval "secret_file=\${$file_variable_name:-}"

    if [ -n "$secret_file" ]; then
        if [ ! -r "$secret_file" ]; then
            echo "Secret file for $variable_name is not readable: $secret_file" >&2
            exit 1
        fi

        secret_value="$(cat "$secret_file")"
        export "$variable_name=$secret_value"
    fi
}

load_secret DB_PASSWORD
load_secret REDIS_PASSWORD
load_secret MAIL_PASSWORD

if [ "${APP_ENV:-}" = "production" ]; then
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

    php -r '
        $key = getenv("APP_KEY") ?: "";
        $encoded = str_starts_with($key, "base64:") ? substr($key, 7) : "";
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            fwrite(STDERR, "APP_KEY must be a generated 32-byte base64 Laravel key.\n");
            exit(1);
        }
    '
fi

exec "$@"
