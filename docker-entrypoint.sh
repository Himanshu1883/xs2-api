#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Run 'php artisan key:generate --show' locally and add it to Railway variables."
    exit 1
fi

php artisan package:discover --no-ansi
php artisan config:cache --no-ansi

if ! php artisan migrate --force --no-ansi; then
    echo "WARNING: migrate --force failed; continuing startup so /up can respond."
fi

php artisan route:cache --no-ansi

# Start queue workers in background (all pipeline queues)
php artisan queue:work --queue=xs2-mapping,xs2-reconcile,xs2-listing-gen,xs2-sync,seller-api,xs2-guest,default --tries=3 --timeout=300 --sleep=3 --max-jobs=500 --max-time=3600 &

# Start scheduler in background
php artisan schedule:work &

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
