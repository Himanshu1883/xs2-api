#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Run 'php artisan key:generate --show' locally and add it to Railway variables."
    exit 1
fi

php artisan config:cache --no-ansi
php artisan route:cache --no-ansi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
